<?php
/**
 * Tests for lib/wellbeing_scoring.php.   Run: php tests/wellbeing_scoring_test.php
 *
 * Sections A–C port the acceptance material supplied with the approved rules
 * (References/My_Imprint_June_Approved_Choices_Update, 2 Nov 2026):
 *   A  primary-choice.test.mjs (22 cases). Values that used the old TF/BS maximum of 18 are
 *      re-expressed for the site maxima (TF 17, BS 16) so each case still tests the same thing.
 *   B  My_Imprint_Near_Tie_Acceptance_Cases (7 percentage boundary cases).
 *   C  My_Imprint_Secondary_and_Route_Acceptance_Cases (20 cases; case 20 is app behaviour).
 * Section D checks the per-answer scoring tables, the Adult check and the safety triggers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/wellbeing_scoring.php';

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
        throw new RuntimeException(($what ? "{$what}: " : '') . 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
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

/** Minimal valid answers: every scored item at zero (1.1 trouble sleeping, 4.1 slow down score nothing). */
function answers(array $overrides = []): array
{
    return array_merge([
        '1.1' => ['1.1.d'], '1.2' => 0, '1.3' => ['1.3.b'], '1.4' => [],
        '2.1' => ['2.1.a'], '2.2' => '2.2.a',
        '3.1' => '3.1.0', '3.2' => '3.2.0', '3.3' => '3.3.0', '3.4' => '3.4.0', '3.5' => '3.5.0',
        '4.1' => ['4.1.j'], '4.2' => '4.2.j', '4.3' => 0,
        '5.1' => ['5.1.a'],
    ], $overrides);
}
function earned(array $answers): array
{
    return array_map('wp_fraction_to_float', wp_pattern_scores($answers));
}
$run = fn(array $scores, bool $adult = false) => wp_resolve_primary($adult, $scores, $adult ? 'AD' : null);

echo "== A. Primary choice (reference cases)\n";
check('Exactly five percentage points included', fn() => same($run(['TH' => 22, 'TF' => '16.15', 'BS' => 0, 'BP' => 0])['candidates'], ['TH', 'TF']));
check('Just outside five excluded before rounding', fn() => same($run(['TH' => 22, 'TF' => '16.149999', 'BS' => 0, 'BP' => 0])['candidates'], ['TH']));
check('Just inside five included before rounding', fn() => same($run(['TH' => 22, 'TF' => '16.150001', 'BS' => 0, 'BP' => 0])['candidates'], ['TH', 'TF']));
check('Exact three-way leaders all offered', fn() => same($run(['TH' => 11, 'TF' => '8.5', 'BS' => 8, 'BP' => 0])['candidates'], ['TH', 'TF', 'BS']));
check('Tied second candidates both offered', fn() => same($run(['TH' => 11, 'TF' => '7.65', 'BS' => '7.2', 'BP' => 0])['candidates'], ['TH', 'TF', 'BS']));
check('All four can qualify', fn() => same($run(['TH' => 11, 'TF' => '8.5', 'BS' => 8, 'BP' => '14.5'])['candidates'], ['TH', 'TF', 'BS', 'BP']));
check('Five raw points is not five percentage points', fn() => same($run(['TH' => 20, 'TF' => 0, 'BS' => 0, 'BP' => 15])['candidates'], ['TH']));
check('Fixed BP maximum is 29', fn() => same($run(['TH' => 0, 'TF' => 0, 'BS' => 0, 'BP' => 29])['candidates'], ['BP']));
check('Adult eligibility takes precedence', fn() => same($run([], true)['kind'], 'adult'));
check('No arbitrary zero-score primary', fn() => same($run(['TH' => 0, 'TF' => 0, 'BS' => 0, 'BP' => 0])['kind'], 'no_supported_pattern'));
check('Zero evidence excluded even in a low positive window', fn() => same($run(['TH' => 1, 'TF' => 0, 'BS' => 0, 'BP' => 0])['candidates'], ['TH']));
check('Missing total rejected', fn() => throws(fn() => $run(['TH' => 11, 'TF' => 9, 'BS' => 9])));
check('Negative total rejected', fn() => throws(fn() => $run(['TH' => '-1', 'TF' => 9, 'BS' => 9, 'BP' => 0])));
check('Out of range total rejected', fn() => throws(fn() => $run(['TH' => 23, 'TF' => 9, 'BS' => 9, 'BP' => 0])));
check('Out of range under the new TF/BS maxima rejected', fn() => [throws(fn() => $run(['TH' => 0, 'TF' => '17.5', 'BS' => 0, 'BP' => 0])), throws(fn() => $run(['TH' => 0, 'TF' => 0, 'BS' => 17, 'BP' => 0]))]);
check('Missing Adult type rejected', fn() => throws(fn() => wp_resolve_primary(true, [], null)));
$resolution = $run(['TH' => 11, 'TF' => '8.5', 'BS' => 8, 'BP' => 0]);
check('Explicit eligible choice accepted', fn() => same(wp_select_primary($resolution, 'TF', 'plain')['accepted_primary'], 'TF'));
check('No fit stays unconfirmed', function () use ($resolution) {
    $r = wp_select_primary($resolution, null, 'plain');
    same($r['accepted_primary'], null);
    same($r['driver_journey_allowed'], false);
});
check('An ineligible choice is rejected', fn() => throws(fn() => wp_select_primary($resolution, 'BP', 'plain')));
check('Presentation preference required separately', fn() => throws(fn() => wp_select_primary($resolution, 'TF', '')));
check('No implicit acceptance from candidate calculation', function () use ($resolution) {
    same($resolution['requires_acceptance'], true);
    same(array_key_exists('accepted_primary', $resolution), false);
});
check('Revised answers produce new eligible candidates', fn() => same($run(['TH' => 0, 'TF' => 0, 'BS' => 0, 'BP' => 29])['candidates'], ['BP']));

echo "\n== B. Near-tie boundary cases (percentages)\n";
foreach ([['40', '35', true], ['40', '35.001', true], ['40', '34.999', false], ['40', '40', true],
          ['39.999', '35', true], ['40.001', '35', false], ['50', '45', true]] as [$top, $second, $expected]) {
    check("top {$top} / second {$second} -> " . ($expected ? 'choice' : 'no choice'),
        fn() => same(wp_within_points(wp_decimal($top), wp_decimal($second), WP_PRIMARY_WINDOW_POINTS), $expected));
}

echo "\n== C. Secondary and route cases\n";
$snappy = fn(string $q44, array $more = []) => answers(array_merge(['4.2' => '4.2.h', '4.4' => $q44], $more));
check('1 Mixed frustration; TF higher -> TF', fn() => same(wp_resolve_secondary($snappy('4.4.d', ['3.3' => '3.3.4']), 'TH')['secondary'], 'TF'));
check('2 Mixed frustration; BP higher -> BP', fn() => same(wp_resolve_secondary($snappy('4.4.d', ['3.5' => '3.5.4']), 'TH')['secondary'], 'BP'));
check('3 Mixed frustration; exact positive TF/BP tie -> offer both, neither/unsure allowed', function () use ($snappy) {
    // 8.5/17 = 14.5/29 = 50%: an exact percentage tie (not reachable with whole-point TF answers).
    $r = wp_resolve_secondary($snappy('4.4.d'), 'TH', ['TH' => [0, 1], 'TF' => [17, 2], 'BS' => [0, 1], 'BP' => [29, 2]]);
    same([$r['kind'], $r['candidates']], ['choice', ['TF', 'BP']]);
    same(wp_select_secondary($r, 'TH', $snappy('4.4.d'), null, 'unsure')['content'], 'primary_only');
});
check('4 Mixed frustration; both scores zero -> no secondary', function () use ($snappy) {
    $r = wp_resolve_secondary($snappy('4.4.d'), 'TH', ['TH' => [5, 1], 'TF' => [0, 1], 'BS' => [0, 1], 'BP' => [0, 1]]);
    same([$r['kind'], $r['content']], ['none', 'primary_only']);
});
check('5 Does not usually spill over -> no mapped secondary', fn() => same(wp_resolve_secondary($snappy('4.4.e'), 'TH')['reason'], 'frustration_does_not_spill_over'));
check('6 Required 4.4 missing -> unresolved, no default', function () {
    $a = answers(['4.2' => '4.2.h']); // 4.4 shown (4.2 snappy) but not answered
    same(wp_resolve_secondary($a, 'TH')['kind'], 'unresolved');
});
check('7 Clear bursts response -> TF', fn() => same(wp_resolve_secondary($snappy('4.4.a'), 'TH')['secondary'], 'TF'));
check('8 Clear builds response -> BP', fn() => same(wp_resolve_secondary($snappy('4.4.b'), 'TH')['secondary'], 'BP'));
check('9 Clear standing ground response -> BP', fn() => same(wp_resolve_secondary($snappy('4.4.c'), 'TH')['secondary'], 'BP'));
check('10 Secondary equals primary -> ranked alternative, no duplicate', function () {
    $a = answers(['4.2' => '4.2.a', '1.1' => ['1.1.e']]); // 4.2 push through = BP = primary; TH 2/22 = 9.1%, BS 1/16 = 6.25%
    $r = wp_resolve_secondary($a, 'BP');
    same([$r['kind'], $r['secondary'], $r['reason']], ['fallback', 'TH', 'direct_matches_primary']);
});
check('11 Highest eligible fallback tie -> offer tied, no five-point window', function () {
    $r = wp_resolve_secondary(answers(['4.2' => '4.2.k']), 'TH',
        ['TH' => [10, 1], 'TF' => [17, 2], 'BS' => [8, 1], 'BP' => [141, 10]]); // TF = BS = 50%, BP 48.6%
    same([$r['kind'], $r['candidates']], ['choice', ['TF', 'BS']]); // BP 48.6% is within 5 points but not tied
});
$weak = wp_resolve_secondary(answers(['4.2' => '4.2.k', '1.1' => ['1.1.a', '1.1.e']]), 'TH'); // BS 1/16 = 6.25%
check('12 Weak fallback selected and explicit fits -> confirmed paired', function () use ($weak) {
    same([$weak['kind'], $weak['secondary'], $weak['weak']], ['fallback', 'BS', true]);
    $s = wp_select_secondary($weak, 'TH', [], null, 'fits');
    same([$s['status'], $s['secondary'], $s['route']], ['confirmed', 'BS', 'TH_BS']);
});
check('13 Weak candidate declined -> primary-only, saved declined', fn() => same(array_intersect_key(wp_select_secondary($weak, 'TH', [], null, 'no'), ['status' => 1, 'route' => 1]), ['status' => 'declined', 'route' => 'TH_NONE']));
check('14 Weak candidate unsure -> primary-only, saved unsure', fn() => same(wp_select_secondary($weak, 'TH', [], null, 'unsure')['status'], 'unsure'));
check('15 Weak candidate skipped -> primary-only, saved skipped', fn() => same(wp_select_secondary($weak, 'TH', [], null, 'skip')['status'], 'skipped'));
check('16 Weak choice unanswered -> primary-only, saved pending', fn() => same(wp_select_secondary($weak, 'TH', [], null, null)['status'], 'pending'));
check('17 Fallback all alternatives zero -> no secondary', fn() => same(wp_resolve_secondary(answers(['4.2' => '4.2.k', '1.1' => ['1.1.a']]), 'TH')['reason'], 'all_alternatives_zero'));
check('18 No accepted primary -> no driver journey, no secondary', function () use ($resolution) {
    same(wp_select_primary($resolution, null, 'characters')['driver_journey_allowed'], false);
    throws(fn() => wp_resolve_secondary(answers(), ''));
});
check('19 Accepted BP and no secondary -> BP_NONE', function () {
    $a = answers(['4.2' => '4.2.a']);
    same(wp_select_secondary(wp_resolve_secondary($a, 'BP'), 'BP', $a, null, null)['route'], 'BP_NONE');
});
echo "SKIP 20 Later secondary confirmation does not silently replace a running journey (app behaviour, not scoring)\n";
check('Strong (>= 25%) fallback needs no confirmation', function () {
    $a = answers(['4.2' => '4.2.k', '3.5' => '3.5.4']); // BP 8/29 = 27.6%
    same(wp_select_secondary(wp_resolve_secondary($a, 'TH'), 'TH', $a, null, null)['status'], 'established');
});
check('Direct 4.2 evidence is kept even with a zero pattern score', fn() => same(wp_resolve_secondary(answers(['4.2' => '4.2.b']), 'TH'), ['kind' => 'direct', 'secondary' => 'BS', 'source' => '4.2']));
check('Adult 4.2 answer is the generic Adult secondary', fn() => same(wp_resolve_secondary(answers(['4.2' => '4.2.e']), 'TF')['secondary'], 'A'));
check('Adult primary with Adult 4.2 answer falls back to patterns', fn() => same(wp_resolve_secondary(answers(['4.2' => '4.2.j', '3.1' => '3.1.2']), 'AS')['secondary'], 'TH'));
check('Choosing the primary in a tie re-enters the fallback', function () use ($snappy) {
    $r = wp_resolve_secondary($snappy('4.4.d'), 'BP', ['TH' => [0, 1], 'TF' => [17, 2], 'BS' => [0, 1], 'BP' => [29, 2]]);
    same(wp_select_secondary($r, 'BP', $snappy('4.4.d'), 'BP', 'fits')['status'], 'reselect');
});

echo "\n== D. Scoring tables, Adult check and safety\n";
check('Teenager Hurting maximum is reachable: 22', fn() => same(earned(answers(['3.1' => '3.1.4', '3.2' => '3.2.4', '1.1' => ['1.1.a', '1.1.e', '1.1.h', '1.1.f'], '1.4' => ['1.4.e', '1.4.f'], '1.5' => '1.5.b', '4.1' => ['4.1.k']]))['TH'], 22.0));
check('Fired Up maximum is reachable: 17 (old 1.3 item dropped)', fn() => same(earned(answers(['3.3' => '3.3.4', '1.1' => ['1.1.c'], '4.1' => ['4.1.i', '4.1.h'], '4.4' => '4.4.a']))['TF'], 17.0));
check('Shutting Down maximum is reachable: 16 (old 1.3 item dropped)', fn() => same(earned(answers(['3.1' => '3.1.4', '4.1' => ['4.1.b', '4.1.f', '4.1.c'], '5.1' => ['5.1.c', '5.1.g'], '1.1' => ['1.1.e']]))['BS'], 16.0));
check('Pushing Through maximum is reachable: 29', fn() => same(earned(answers(['3.5' => '3.5.4', '3.4' => '3.4.4', '4.1' => ['4.1.a', '4.1.d', '4.1.g', '4.1.h', '4.1.l'], '1.1' => ['1.1.h', '1.1.b', '1.1.g'], '4.4' => '4.4.b']))['BP'], 29.0));
check('Goals (1.3) never score', fn() => same(earned(answers(['1.3' => ['1.3.a', '1.3.c']])), ['TH' => 0.0, 'TF' => 0.0, 'BS' => 0.0, 'BP' => 0.0]));
check('1.5 Yes halves every 1.1 point, nothing else', function () {
    $base = ['1.1' => ['1.1.a', '1.1.h'], '1.4' => ['1.4.a'], '3.5' => '3.5.2'];
    same(earned(answers($base + ['1.5' => '1.5.b'])), ['TH' => 3.0, 'TF' => 0.0, 'BS' => 0.0, 'BP' => 6.0]);
    same(earned(answers($base + ['1.5' => '1.5.a'])), ['TH' => 1.5, 'TF' => 0.0, 'BS' => 0.0, 'BP' => 5.0]);
});
check('Hidden 4.4 earns nothing', fn() => same(earned(answers(['4.4' => '4.4.b']))['BP'], 0.0));
check('Scale answers accept option ids or 0-4', fn() => same(earned(answers(['3.5' => 3]))['BP'], 6.0));

$adult = fn(array $o) => wp_score_questionnaire(answers($o));
check('Low scores, low impact -> Adult Steady', fn() => same($adult(['4.1' => ['4.1.a']])['primary'], ['kind' => 'adult', 'candidates' => ['AD'], 'adult_type' => 'AD', 'requires_acceptance' => true]));
check('Below 35% stays Adult; above it blocks the Adult', function () use ($adult) {
    $below = $adult(['4.1' => ['4.1.a', '4.1.d', '4.1.g', '4.1.l'], '1.1' => ['1.1.b', '1.1.d']]); // BP 10/29 = 34.48%
    same([$below['patterns']['BP']['percent'], $below['adult']['eligible']], [34.48, true]);
    $above = $adult(['4.1' => ['4.1.a', '4.1.d', '4.1.g', '4.1.l'], '1.1' => ['1.1.h']]);           // BP 11/29 = 37.93%
    same([$above['patterns']['BP']['percent'], $above['adult']['eligible']], [37.93, false]);
});
check('Reach out / slow down raises the Adult limit to 45%', function () use ($adult) {
    $o = ['3.5' => '3.5.4']; // with 4.1 push through: BP 11/29 = 37.9%, between 35% and 45%
    same($adult($o + ['4.1' => ['4.1.a']])['adult']['eligible'], false);
    same($adult($o + ['4.1' => ['4.1.a', '4.1.e']])['adult']['eligible'], true);
});
check('Interference 6 blocks the Adult', fn() => same($adult(['1.2' => 6, '4.1' => ['4.1.a']])['adult']['eligible'], false));
check('4.3 at 3 blocks the Adult', fn() => same($adult(['4.3' => 3, '4.1' => ['4.1.a']])['adult']['eligible'], false));
check('1.5 Yes lifts the interference limit and hard stop', function () use ($adult) {
    $r = $adult(['1.2' => 9, '1.4' => ['1.4.a'], '1.5' => '1.5.a', '4.1' => ['4.1.a']]);
    same([$r['adult']['eligible'], $r['adult']['type'], $r['recent_change']], [true, 'AS', true]);
});
check('Adult Stretched: overwhelmed, two life areas or interference 4-5', function () use ($adult) {
    same($adult(['1.1' => ['1.1.g'], '4.1' => ['4.1.a']])['adult']['type'], 'AS');
    same($adult(['1.4' => ['1.4.a', '1.4.b'], '1.5' => '1.5.b', '4.1' => ['4.1.a']])['adult']['type'], 'AS');
    same($adult(['1.2' => 4, '4.1' => ['4.1.a']])['adult']['type'], 'AS');
    same($adult(['1.2' => 3, '4.1' => ['4.1.a']])['adult']['type'], 'AD');
});
check('Adult fails -> highest pattern is the primary', fn() => same($adult(['1.2' => 7, '3.5' => '3.5.4', '4.1' => ['4.1.a']])['primary']['candidates'], ['BP']));

$safety = fn(array $o) => wp_safety(answers($o))['triggers'];
check('Safety A: interference 9+ and coping makes it worse', fn() => same($safety(['1.2' => 9, '4.3' => 4]), ['A_severe_impact_failing_coping']));
check('Safety B: fired up at the extreme', fn() => same($safety(['3.3' => '3.3.4', '4.1' => ['4.1.i'], '1.2' => 8]), ['B_fired_up_extreme']));
check('Safety C: substances as the main coping', fn() => same($safety(['1.4' => ['1.4.j'], '1.5' => '1.5.b', '4.2' => '4.2.f']), ['C_substances_main_coping']));
check('Safety D: money + substances + interference 8', fn() => same($safety(['1.4' => ['1.4.b', '1.4.j'], '1.5' => '1.5.b', '1.2' => 8]), ['D_compounding_load']));
check('Safety: accident or frightening event', fn() => same($safety(['1.4' => ['1.4.i'], '1.5' => '1.5.b']), ['accident_or_frightening_event']));
check('Unsafe at home is a separate support concern, not a trigger', function () {
    $s = wp_safety(answers(['1.4' => ['1.4.k'], '1.5' => '1.5.b']));
    same([$s['safety_check_required'], $s['support_concerns']], [false, ['unsafe_at_home_or_relationship']]);
});
check('No triggers on ordinary answers', fn() => same(wp_safety(answers())['safety_check_required'], false));

check('Missing required answers are rejected', function () {
    foreach (['1.1', '1.2', '3.3', '4.1', '4.2', '4.3'] as $q) {
        $a = answers();
        unset($a[$q]);
        throws(fn() => wp_score_questionnaire($a));
    }
    throws(fn() => wp_score_questionnaire(answers(['1.4' => ['1.4.a']]))); // 1.5 then required
});

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
