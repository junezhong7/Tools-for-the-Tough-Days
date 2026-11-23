<?php
/**
 * Tests for lib/wellbeing_report.php (report assembly only, no database).
 * Run: php tests/wellbeing_report_test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/wellbeing_report.php';

$pass = 0;
$fail = 0;
function check(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        $pass++;
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $fail++;
        echo "FAIL {$name}: " . $e->getMessage() . "\n";
    }
}
function same($actual, $expected, string $what = ''): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(($what ? "{$what}: " : '') . 'expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException($what);
    }
}
function throws(callable $fn): void
{
    try {
        $fn();
    } catch (WellbeingScoringError $e) {
        return;
    }
    throw new RuntimeException('expected a WellbeingScoringError');
}

/** Valid answers with a clear Pushing Through primary and a direct Shutting Down secondary. */
function answers(array $overrides = []): array
{
    return array_merge([
        '1.1' => ['1.1.g'], '1.2' => 5, '1.4' => [],
        '3.1' => '3.1.0', '3.2' => '3.2.1', '3.3' => '3.3.2', '3.4' => '3.4.3', '3.5' => '3.5.4',
        '4.1' => ['4.1.a', '4.1.g'], '4.2' => '4.2.b', '4.3' => 3,
        '5.1' => ['5.1.a', '5.1.d'],
        '6.5' => ['6.5.c', '6.5.g'], '6.5b' => '6.5.c',
    ], $overrides);
}
function primary_of(array $a): array
{
    return wp_score_questionnaire($a)['primary']['candidates'];
}
/** All participant text in a report, one string per block line. */
function texts(array $report): array
{
    $out = [];
    $walk = static function (array $blocks) use (&$out): void {
        foreach ($blocks as $b) {
            if (isset($b['text'])) {
                $out[] = $b['text'];
            }
            foreach ($b['items'] ?? [] as $i) {
                $out[] = is_array($i) ? $i['label'] : $i;
            }
            foreach ($b['rows'] ?? [] as $r) {
                array_push($out, ...$r);
            }
        }
    };
    foreach ($report['sections'] as $s) {
        $out[] = $s['title'];
        $walk($s['blocks']);
    }
    $walk($report['support_first'] ?? []);
    return $out;
}
function section(array $report, int $id): array
{
    foreach ($report['sections'] as $s) {
        if ($s['id'] === $id) {
            return $s;
        }
    }
    throw new RuntimeException("section {$id} missing");
}
function section_text(array $report, int $id): string
{
    return implode("\n", texts(['sections' => [section($report, $id)]]));
}
function no_placeholders(array $report): void
{
    foreach (texts($report) as $t) {
        if (preg_match('/\{[a-z0-9]+\}|\[(saved|rating|selected|matching|exact|4\.2)|\bTODO\b/', $t)) {
            throw new RuntimeException('placeholder left in: ' . $t);
        }
    }
}

$c = wr_content();

check('Fixture: Pushing Through primary, direct Shutting Down secondary', function () {
    same(primary_of(answers()), ['BP']);
    same(wp_resolve_secondary(answers(), 'BP')['kind'], 'direct');
});

check('Characters: ten sections, 9 and 11 withheld, numbered 1–10', function () {
    $r = wr_build_report(answers(), 'BP', 'characters');
    same(array_column($r['sections'], 'id'), [1, 2, 3, 4, 5, 6, 7, 8, 10, 12]);
    same(array_column($r['sections'], 'number'), range(1, 10));
    same($r['section_count'], 10);
    same($r['primary'], ['id' => 'BP', 'title' => 'Bodyguard: Pushing Through']);
    same($r['question'], null);
    no_placeholders($r);
});

check('Characters: named primary, secondary and their pairing', function () use ($c) {
    $r = wr_build_report(answers(), 'BP', 'characters');
    ok(str_contains(section_text($r, 3), $c['s3']['primary']['BP']['intro'][0]), 'BP intro in section 3');
    $s4 = section_text($r, 4);
    ok(str_contains($s4, $c['s4']['secondary']['BS']['intro'][0]), 'BS secondary in section 4');
    ok(str_contains($s4, $c['s4']['pairs']['BP_BS']), 'BP_BS pairing');
    ok(!str_contains($s4, '90-day'), 'no 90-day journey line in section 4');
    $s10 = section_text($r, 10);
    ok(str_contains($s10, $c['s10']['step1']['BS']) && str_contains($s10, $c['s10']['closing']['BS']), 'secondary variants in the guide');
    ok(str_contains(section_text($r, 6), $c['s6']['closing']['BP']), 'primary closing in section 6');
});

check('Section 3 strengths and early signs are lists', function () {
    $r = wr_build_report(answers(), 'BP', 'characters');
    $lists = array_values(array_filter(section($r, 3)['blocks'], fn($b) => $b['type'] === 'list'));
    same(count($lists), 2);
    same($lists[0]['items'][0], 'getting through hard times without falling over');
});

check('Plain language: no character names anywhere, answer-led sections', function () use ($c) {
    $r = wr_build_report(answers(), 'BP', 'plain');
    same($r['primary']['title'], 'Keeping going under pressure');
    same(section($r, 2)['title'], 'Understanding your responses');
    foreach (texts($r) as $t) {
        ok(!preg_match('/Bodyguard|Teenager|the Adult\b|crew/', $t), 'character language in: ' . $t);
    }
    ok(str_contains(section_text($r, 3), 'you selected “Push through and keep busy” and “Overthink and analyse”'), 'plain 4.1 list');
    ok(str_contains(section_text($r, 4), 'you selected “Withdraw and go quiet”'), 'plain 4.2');
    ok(str_contains(section_text($r, 6), $c['s6']['closing']['neutral']), 'neutral closing');
    ok(str_contains(section_text($r, 10), $c['s10']['step1']['neutral']), 'neutral guide');
    same($r['question'], null);
    no_placeholders($r);
});

check('Weak fallback secondary: pending question, then each answer', function () use ($c) {
    // 4.2 maps to the primary (BP), so the fallback is the highest other pattern: TH at 1/22 (weak).
    $a = answers(['4.2' => '4.2.a', '3.2' => '3.2.1', '3.3' => '3.3.0', '1.1' => ['1.1.g']]);
    same(primary_of($a), ['BP']);
    $pending = wr_build_report($a, 'BP', 'characters');
    same($pending['question']['kind'], 'confirm');
    same(array_column($pending['question']['candidates'], 'id'), ['TH']);
    ok(str_contains(section_text($pending, 4), $c['s4']['transition']['pending']), 'pending transition');
    same(end(section($pending, 4)['blocks'])['type'], 'question');

    $fits = wr_build_report($a, 'BP', 'characters', 'TH', 'fits');
    same($fits['question'], null);
    ok(str_contains(section_text($fits, 4), $c['s4']['pairs']['BP_TH']), 'confirmed pairing');

    foreach (['no', 'unsure', 'skip'] as $response) {
        $r = wr_build_report($a, 'BP', 'characters', 'TH', $response);
        ok(str_contains(section_text($r, 4), $c['s4']['transition']['declined']), "declined transition for {$response}");
        ok(!str_contains(section_text($r, 4), $c['s4']['pairs']['BP_TH']), "no pairing for {$response}");
    }
});

check('Secondary answers are validated', function () {
    $a = answers(['4.2' => '4.2.a', '3.2' => '3.2.1', '3.3' => '3.3.0']);
    $state = wr_secondary_state($a, 'BP', null, null);
    throws(fn() => wr_validate_secondary_answer($state, 'BS', 'fits'));   // not offered
    throws(fn() => wr_validate_secondary_answer($state, null, 'fits'));   // fits needs a pick
    throws(fn() => wr_validate_secondary_answer($state, 'TH', 'maybe'));
    wr_validate_secondary_answer($state, 'TH', 'fits');
    wr_validate_secondary_answer($state, null, 'unsure');
    $answered = wr_secondary_state($a, 'BP', 'TH', 'no');
    throws(fn() => wr_validate_secondary_answer($answered, 'TH', 'fits')); // already answered
});

check('No distinct secondary: approved primary-only transition', function () use ($c) {
    // 4.2 Slow down (Adult) is not distinct for an Adult primary; all other patterns zero.
    $a = [
        '1.1' => ['1.1.d'], '1.2' => 2, '1.4' => [],
        '3.1' => '3.1.0', '3.2' => '3.2.0', '3.3' => '3.3.0', '3.4' => '3.4.0', '3.5' => '3.5.0',
        '4.1' => ['4.1.j'], '4.2' => '4.2.j', '4.3' => 0, '5.1' => ['5.1.a'], '6.5' => ['6.5.o'],
    ];
    same(primary_of($a), ['AD']);
    $r = wr_build_report($a, 'AD', 'characters');
    same(section_text($r, 4), "When it gets really hard\n" . $c['s4']['transition']['none']);
    ok(str_contains(section_text($r, 10), $c['s10']['step2_none']), 'no named activity');
    ok(str_contains(section_text($r, 8), $c['s8']['recharge']['depends']) && str_contains(section_text($r, 8), $c['s8']['settle']['none']), 'unsure activities');
});

check('Section 5: rating table, levels and less-like-you groups', function () use ($c) {
    $r = wr_build_report(answers(), 'BP', 'characters');
    $s5 = section($r, 5);
    $table = array_values(array_filter($s5['blocks'], fn($b) => $b['type'] === 'table'))[0];
    same(array_column($table['rows'], 1), ['Not like me', 'A little like me', 'Somewhat like me', 'Quite like me', 'Very like me']);
    $text = section_text($r, 5);
    ok(str_contains($text, 'The statements you rated quite like you or very like you were “Putting everyone else first” and “High standards and finding it hard to switch off”.'), 'high sentence');
    ok(str_contains($text, $c['s5']['statements']['3.4']['high']['title']) && str_contains($text, 'You said this is “quite like me”.'), 'high block with label');
    ok(str_contains($text, $c['s5']['statements']['3.3']['mid']['title']) && str_contains($text, 'You said this is “somewhat like me”.'), 'middle block');
    ok(str_contains($text, 'You rated the following as not like you: worrying that people will leave, or that something is wrong with you.'), 'zeros');
    ok(str_contains($text, 'You rated the following as a little like you: doubting you can handle things on your own.'), 'ones');
    ok(!str_contains($text, $c['s5']['statements']['3.1']['high']['title']), 'no block for a zero');
});

check('Section 6: opening, really-hard context and one block per response', function () use ($c) {
    $a = answers(['4.1' => ['4.1.a', '4.1.b'], '4.2' => '4.2.b']);
    $r = wr_build_report($a, primary_of($a)[0], 'characters');
    $text = section_text($r, 6);
    ok(str_contains($text, 'you tend to push through and keep busy and withdraw and go quiet. You rated how these ways of coping are working for you as 3 out of 4'), 'opening');
    ok(str_contains($text, 'When things become especially difficult, you selected “Withdraw and go quiet”.'), '4.2 context');
    ok(str_contains($text, $c['s6']['helpful']['high']), 'rating 3 response');
    same(substr_count($text, $c['s6']['coping']['4.1.b']), 1, 'withdraw block once');
    ok(str_contains($text, 'You chose this for when things get tough and for when things become especially difficult.'), 'both contexts named');
});

check('Section 7: life areas, impact band and recent change', function () use ($c) {
    $a = answers(['1.4' => ['1.4.a', '1.4.b'], '1.5' => '1.5.a', '1.2' => 8]);
    $r = wr_build_report($a, primary_of($a)[0], 'characters');
    $text = section_text($r, 7);
    ok(str_contains($text, 'You told us that work or study and money are connected to how you have been feeling.'), 'areas sentence');
    ok(str_contains($text, 'You rated the effect on your day-to-day life as 8 out of 10.'), 'impact');
    ok(str_contains($text, $c['s7']['recent']) && str_contains($text, $c['s7']['impact_high']), 'recent + high');
    ok(str_contains($text, $c['s7']['areas']['1.4.b']['text']), 'money block');
    $one = wr_build_report(answers(['1.4' => ['1.4.e'], '1.5' => '1.5.b', '1.2' => 0]), 'BP', 'characters');
    ok(str_contains(section_text($one, 7), 'loss or grief is connected') && str_contains(section_text($one, 7), $c['s7']['impact_low']), 'singular + low');
    ok(!str_contains(section_text($one, 7), $c['s7']['recent']), 'no recent change when 1.5 is No');
});

check('No one to turn to: restricted variants, no contact suggestions', function () use ($c) {
    $a = answers(['5.1' => ['5.1.a', '5.1.h'], '4.1' => ['4.1.a', '4.1.e'], '6.5' => ['6.5.b', '6.5.g'], '6.5b' => '6.5.b']);
    $r = wr_build_report($a, primary_of($a)[0], 'characters');
    $s8 = section_text($r, 8);
    ok(str_contains($s8, $c['s8']['unavailable']), 'unavailable acknowledgement');
    ok(!str_contains($s8, $c['s8']['prefs']['5.1.a']['text']), 'no personal-support blocks');
    ok(str_contains($s8, $c['s8']['recharge']['alone']) && str_contains($s8, $c['s8']['settle']['still']), 'trusted person ignored in mappings');
    ok(str_contains(section_text($r, 6), $c['restricted']['s6_4.1.e']), 'restricted reach-out text');
    $s10 = section_text($r, 10);
    ok(str_contains($s10, $c['s10']['step2_contact_excluded']) && str_contains($s10, $c['restricted']['s10_step3']), 'guide variants');
    same($r['support_first'], null);
});

check('Unsafe at home: specialist support first, not listed as a life area', function () use ($c) {
    $a = answers(['1.4' => ['1.4.c', '1.4.k'], '1.5' => '1.5.b']);
    $r = wr_build_report($a, primary_of($a)[0], 'characters');
    ok($r['support_first'] !== null, 'support first');
    same($r['support_first'][2]['items'][0]['href'], 'tel:000');
    $s7 = section_text($r, 7);
    ok(str_contains($s7, 'You told us that relationships or family is connected to how you have been feeling.'), 'unsafe not in the life-area sentence');
    ok(str_contains(section_text($r, 8), $c['s8']['unsafe']), 'unsafe wording in section 8');
});

check('Pressure line only when that response was chosen', function () use ($c) {
    $with = wr_build_report(answers(['4.1' => ['4.1.a', '4.1.l']]), 'BP', 'characters');
    ok(str_contains(section_text($with, 10), $c['s10']['pressure']), 'shown');
    $without = wr_build_report(answers(), 'BP', 'characters');
    ok(!str_contains(section_text($without, 10), $c['s10']['pressure']), 'not shown for a Pushing Through primary alone');
});

check('About this report names the guide, not a section number', function () {
    $r = wr_build_report(answers(), 'BP', 'characters');
    ok(str_contains(section_text($r, 12), 'in Your Tough Day Guide.') && !str_contains(section_text($r, 12), 'Section 10'), 'renamed reference');
});

check('Only an accepted primary can be used', function () {
    throws(fn() => wr_build_report(answers(), 'XX', 'characters'));
});

check('Stored answers keep only report questions and simple values', function () {
    $clean = wr_clean_answers(answers(['1.3' => ['1.3.a'], '6.3a' => ['mode' => 'time'], '4.1' => ['4.1.a', ['x'], 5]]));
    ok(!isset($clean['1.3']) && !isset($clean['6.3a']), 'unused questions dropped');
    same($clean['4.1'], ['4.1.a']);
});

check('Random sweep: every result, both languages, every secondary answer', function () {
    mt_srand(20261123);
    $pick = static function (string $q, array $letters, int $min, int $max): array {
        shuffle($letters);
        return array_map(fn($l) => "{$q}.{$l}", array_slice($letters, 0, mt_rand($min, $max)));
    };
    $built = 0;
    for ($i = 0; $i < 600; $i++) {
        $a = [
            '1.1' => $pick('1.1', range('a', 'h'), 1, 4),
            '1.2' => mt_rand(0, 10),
            '1.4' => $pick('1.4', range('a', 'k'), 0, 3),
            '4.1' => $pick('4.1', range('a', 'l'), 1, 4),
            '4.2' => '4.2.' . chr(mt_rand(ord('a'), ord('l'))),
            '4.3' => mt_rand(0, 4),
            '5.1' => $pick('5.1', range('a', 'h'), 1, 3),
            '6.5' => mt_rand(0, 9) === 0 ? ['6.5.o'] : $pick('6.5', range('a', 'n'), 1, 4),
        ];
        foreach (['3.1', '3.2', '3.3', '3.4', '3.5'] as $s) {
            $a[$s] = $s . '.' . mt_rand(0, 4);
        }
        if ($a['1.4'] !== []) {
            $a['1.5'] = mt_rand(0, 1) ? '1.5.a' : '1.5.b';
        }
        if (wp_shows_4_4($a)) {
            $a['4.4'] = '4.4.' . chr(mt_rand(ord('a'), ord('e')));
        }
        $named = array_values(array_filter($a['6.5'], fn($v) => preg_match('/^6\.5\.[a-m]$/', $v)));
        if (count($named) >= 2) {
            $a['6.5b'] = $named[array_rand($named)];
        }
        $result = wp_score_questionnaire($a);
        foreach ($result['primary']['candidates'] as $primary) {
            foreach (['characters', 'plain'] as $language) {
                $r = wr_build_report($a, $primary, $language);
                no_placeholders($r);
                $built++;
                if ($r['question'] !== null) {
                    foreach ($r['question']['candidates'] as $cand) {
                        foreach (['fits', 'no', 'unsure', 'skip'] as $resp) {
                            no_placeholders(wr_build_report($a, $primary, $language, $cand['id'], $resp));
                            $built++;
                        }
                    }
                }
            }
        }
    }
    ok($built > 1000, "built {$built} reports");
    echo "      ({$built} reports built)\n";
});

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
