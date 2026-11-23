<?php
/**
 * Tools for the Tough Days — Wellbeing Profile full report ($39.95 one-off)
 *
 * Assembly follows "Appendix A Assembly rules for June" in Nic's consolidated master v4
 * (2 Nov 2026): the report is put together from prewritten copy (lib/wellbeing_report_content.php,
 * generated from the master) and the participant's saved answers. Nothing is generated at runtime.
 *
 * Report-only purchase (Nic's email of 23 Nov 2026), so:
 *  - Sections 9 (90-day journey) and 11 (looking ahead / membership) are withheld until Nic
 *    supplies report-only wording (WR_WITHHELD_SECTIONS).
 *  - The closing paragraph of each Section 4 secondary block that points to the 90-day journey
 *    is left out (done in the generator).
 *
 * Site decisions not spelled out in the master (flagged for Nic):
 *  - Appendix B "unavailable support" variants are also used when the unsafe concern is selected
 *    (the master says unsafe needs at least the same restrictions).
 *  - The unsafe concern (1.4.k) is not repeated in the Section 7 life-area sentence; specialist
 *    support is shown first instead.
 *  - A secondary choice never offers the accepted primary itself.
 *
 * The purchase is NOT platform access: it never writes a `subscriptions` row.
 */

declare(strict_types=1);

require_once __DIR__ . '/wellbeing_scoring.php';

const WR_PRODUCT_KEY = 'wellbeing_report';
const WR_PRODUCT_NAME = 'Full Wellbeing Profile Report';
const WR_PRICE_CENTS = 3995;
const WR_DEFAULT_PRICE_ID = 'price_1Udf9qC8xESC1BMDRYlLXX4Y'; // live; sand can override with a test price
const WR_WITHHELD_SECTIONS = [9, 11];
const WR_UNPURCHASED_RETENTION_DAYS = 30;  // master §12: non-buyers' answers are not kept

/** Only what the report (and scoring) needs is stored. */
const WR_STORED_QUESTIONS = ['1.1', '1.2', '1.4', '1.5', '3.1', '3.2', '3.3', '3.4', '3.5',
                             '4.1', '4.2', '4.3', '4.4', '5.1', '6.5', '6.5b'];

const WR_TITLES = [
    'TH' => ['Teenager: Hurting', 'Feeling hurt or disconnected'],
    'TF' => ['Teenager: Fired Up', 'Responding with energy or urgency'],
    'BS' => ['Bodyguard: Shutting Down', 'Pulling back when things feel too much'],
    'BP' => ['Bodyguard: Pushing Through', 'Keeping going under pressure'],
    'AS' => ['Adult: Stretched', 'Managing demands while feeling stretched'],
    'AD' => ['Adult: Steady', 'Pausing and responding with perspective'],
];

/** Questionnaire option labels (wellbeing-profile.html), for quoting a participant's choice. */
const WR_COPING_LABELS = [
    'a' => 'Push through and keep busy', 'b' => 'Withdraw and go quiet', 'c' => 'Distract yourself',
    'd' => 'Get hard on yourself', 'e' => 'Reach out to someone', 'f' => 'Try to numb it out',
    'g' => 'Overthink and analyse', 'h' => 'Get short or snappy with people', 'i' => 'Act first, think later',
    'j' => 'Slow down and work out what I need', 'k' => 'Look for comfort or reassurance',
    'l' => 'Put pressure on myself to get everything right, even when things are difficult',
];
/** The same choices as phrases that follow "you tend to". */
const WR_COPING_PHRASES = [
    'a' => 'push through and keep busy', 'b' => 'withdraw and go quiet', 'c' => 'distract yourself',
    'd' => 'get hard on yourself', 'e' => 'reach out to someone', 'f' => 'try to numb it out',
    'g' => 'overthink and analyse', 'h' => 'get short or snappy with people', 'i' => 'act first and think later',
    'j' => 'slow down and work out what you need', 'k' => 'look for comfort or reassurance',
    'l' => 'put pressure on yourself to get everything right',
];
const WR_LIFE_AREA_PHRASES = [
    'a' => 'work or study', 'b' => 'money', 'c' => 'relationships or family', 'd' => 'health',
    'e' => 'loss or grief', 'f' => 'loneliness or feeling cut off from people',
    'g' => 'becoming a parent or caring for someone who’s unwell', 'h' => 'moving house or city or another big change',
    'i' => 'an accident or a frightening event', 'j' => 'alcohol or other substances',
];
const WR_SUPPORT_PHRASES = [
    'a' => 'having someone just listen', 'b' => 'being given practical steps', 'c' => 'being left to work it out alone',
    'd' => 'gentle encouragement', 'e' => 'being checked in on', 'f' => 'honest, direct feedback',
    'g' => 'not being a burden to anyone',
];

/** Report navigation groups (same as the page's report sketch). */
const WR_GROUPS = [
    1 => 'Getting oriented', 2 => 'Getting oriented',
    3 => 'Your patterns', 4 => 'Your patterns', 5 => 'Your patterns', 6 => 'Your patterns',
    7 => 'Your life and resources', 8 => 'Your life and resources',
    9 => 'Your next steps', 10 => 'Your next steps', 11 => 'Your next steps',
    12 => 'About the report',
];

function wr_content(): array
{
    static $content = null;
    return $content ??= require __DIR__ . '/wellbeing_report_content.php';
}

function wr_price_id(): string
{
    $fromEnv = trim((string) (getenv('STRIPE_PRICE_WELLBEING_REPORT') ?: ''));
    return $fromEnv !== '' ? $fromEnv : WR_DEFAULT_PRICE_ID;
}

// ─────────────────────────────────────────────────────────────────────────────
// Small helpers
// ─────────────────────────────────────────────────────────────────────────────

/** "a", "a and b", "a, b and c" */
function wr_join(array $items): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return $items[0] ?? '';
    }
    return implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
}

function wr_quote(string $text): string
{
    return '“' . $text . '”';
}

function wr_letter(string $optionId): string
{
    return substr($optionId, strrpos($optionId, '.') + 1);
}

function wr_p(string $text): array { return ['type' => 'p', 'text' => $text]; }
function wr_h(string $text): array { return ['type' => 'h', 'text' => $text]; }
function wr_note(string $text): array { return ['type' => 'note', 'text' => $text]; }

/** Generator items (strings or ['list' => [...]]) to blocks. */
function wr_items(array $items): array
{
    return array_map(static fn($i) => is_array($i) ? ['type' => 'list', 'items' => $i['list']] : wr_p($i), $items);
}

/** Who can be suggested as personal support. */
function wr_support_flags(array $answers): array
{
    $unsafe = in_array('1.4.k', wp_choices($answers, '1.4'), true);
    $unavailable = in_array('5.1.h', wp_choices($answers, '5.1'), true);
    return ['unsafe' => $unsafe, 'unavailable' => $unavailable, 'restricted' => $unsafe || $unavailable];
}

// ─────────────────────────────────────────────────────────────────────────────
// Secondary state for a saved run
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @return array{resolution: array, selection: array, question: ?array}
 * question is set while a weak or tied secondary still needs the participant's answer.
 */
function wr_secondary_state(array $answers, string $primary, ?string $selected, ?string $response): array
{
    $resolution = wp_resolve_secondary($answers, $primary);
    if ($resolution['kind'] === 'choice') {
        // Never offer the accepted primary as its own secondary.
        $resolution['candidates'] = array_values(array_filter($resolution['candidates'], fn($c) => $c !== $primary));
        if ($resolution['candidates'] === []) {
            $resolution = ['kind' => 'none', 'reason' => 'choice_only_primary', 'content' => 'primary_only'];
        }
    }
    $selection = wp_select_secondary($resolution, $primary, $answers, $selected, $response);

    $question = null;
    if ($selection['status'] === 'pending') {
        $candidates = $resolution['kind'] === 'fallback' ? [$resolution['secondary']] : $resolution['candidates'];
        $question = [
            'kind' => count($candidates) === 1 ? 'confirm' : 'choice',
            'candidates' => array_map(static fn($id) => [
                'id' => $id,
                'title' => WR_TITLES[$id][0],
                'description' => wr_content()['s4']['secondary'][$id]['intro'][0],
            ], $candidates),
        ];
    }
    return ['resolution' => $resolution, 'selection' => $selection, 'question' => $question];
}

/** Is ($selected, $response) a valid answer to the pending question? */
function wr_validate_secondary_answer(array $state, ?string $selected, string $response): void
{
    if ($state['question'] === null) {
        throw new WellbeingScoringError('This question has already been answered.');
    }
    if (!in_array($response, ['fits', 'no', 'unsure', 'skip'], true)) {
        throw new WellbeingScoringError('Unknown answer.');
    }
    $ids = array_column($state['question']['candidates'], 'id');
    if ($selected !== null && !in_array($selected, $ids, true)) {
        throw new WellbeingScoringError('That description was not offered.');
    }
    if ($response === 'fits' && $selected === null) {
        throw new WellbeingScoringError('Choose a description, or choose “Neither / I’m not sure”.');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Report assembly (pure)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param string      $primary   accepted primary: TH TF BS BP AS AD
 * @param string      $language  'characters' | 'plain'
 */
function wr_build_report(array $answers, string $primary, string $language, ?string $secondarySelected = null, ?string $secondaryResponse = null): array
{
    if (!isset(WR_TITLES[$primary])) {
        throw new WellbeingScoringError('An accepted primary is required for the full report.');
    }
    $c = wr_content();
    $plain = $language === 'plain';
    $support = wr_support_flags($answers);
    $secondary = wr_secondary_state($answers, $primary, $secondarySelected, $secondaryResponse);
    $paired = !$plain && $secondary['selection']['content'] === 'paired';
    $secondaryId = $paired ? $secondary['selection']['secondary'] : null;
    $question = $plain ? null : $secondary['question'];

    $q41 = array_values(array_filter(wp_choices($answers, '4.1'), static fn($v) => is_string($v)));
    $q42 = wp_single($answers, '4.2');

    $sections = [];

    // 1 How to read this report
    $sections[1] = array_merge(
        array_map('wr_p', $c['s1']['opening']),
        array_map('wr_p', $plain ? $c['s1']['plain'] : $c['s1']['characters']),
        array_map('wr_p', $c['s1']['support'])
    );

    // 2 Meet your crew / Understanding your responses
    $sections[2] = array_map('wr_p', $plain ? $c['s2']['plain'] : $c['s2']['characters']);

    // 3 When things get tough
    if ($plain) {
        $labels = array_map(static fn($id) => wr_quote(WR_COPING_LABELS[wr_letter($id)]), $q41);
        $sections[3] = [
            wr_p(str_replace('{q41}', wr_join($labels), $c['s3']['plain']['template'])),
            wr_p($c['s3']['plain']['follow']),
        ];
    } else {
        $block = $c['s3']['primary'][$primary];
        $sections[3] = array_map('wr_p', $block['intro']);
        foreach ($block['blocks'] as $b) {
            $sections[3][] = wr_h($b['heading']);
            array_push($sections[3], ...wr_items($b['items']));
        }
    }

    // 4 When it gets really hard
    if ($plain) {
        $sections[4] = [wr_p(str_replace('{q42}', wr_quote(WR_COPING_LABELS[wr_letter($q42)]), $c['s4']['plain']))];
    } elseif ($paired) {
        $block = $c['s4']['secondary'][$secondaryId];
        $sections[4] = array_map('wr_p', $block['intro']);
        foreach ($block['blocks'] as $b) {
            $items = $b['items'];
            if ($support['restricted']) {
                if ($b['heading'] === 'What tends to help in those moments' && isset($c['restricted']['s4_help_' . $secondaryId])) {
                    $items[0] = $c['restricted']['s4_help_' . $secondaryId];
                }
                if ($b['heading'] === 'What happens' && $secondaryId === 'A') {
                    $items[0] = $c['restricted']['s4_happens_A'];
                }
            }
            $sections[4][] = wr_h($b['heading']);
            array_push($sections[4], ...wr_items($items));
        }
        $pair = $c['s4']['pairs'][$primary . '_' . $secondaryId];
        if ($support['restricted'] && $primary . '_' . $secondaryId === 'TF_A') {
            $last = 'If you decide that a repair is needed, choose a manageable and safe way to make it; direct contact with someone unsafe or unavailable is not required.';
            $pair = substr($pair, 0, -strlen($last)) . $c['restricted']['pair_TF_A_repair'];
        }
        $sections[4][] = wr_h($c['s4']['pairs_heading']);
        $sections[4][] = wr_p($pair);
    } else {
        $status = $secondary['selection']['status'];
        $key = $status === 'pending' ? 'pending' : (in_array($status, ['declined', 'unsure', 'skipped'], true) ? 'declined' : 'none');
        $sections[4] = [wr_p($c['s4']['transition'][$key])];
        if ($question !== null) {
            $sections[4][] = ['type' => 'question'];
        }
    }

    // 5 What is underneath
    $ratings = [];
    foreach ($c['s5']['statements'] as $qid => $_) {
        $ratings[$qid] = wp_scale($answers, $qid);
    }
    $s5 = [wr_p($c['s5']['opening'])];
    $high = array_keys(array_filter($ratings, static fn($r) => $r >= 3));
    if ($high !== []) {
        $s5[] = wr_p(str_replace('{list}', wr_join(array_map(static fn($q) => wr_quote($c['s5']['statements'][$q]['title']), $high)), $c['s5']['high_sentence']));
    }
    $rows = [];
    foreach ($c['s5']['statements'] as $qid => $s) {
        $rows[] = [$s['statement'], ucfirst($c['s5']['labels'][$ratings[$qid]])];
    }
    $s5[] = ['type' => 'table', 'head' => ['Statement', 'Your answer'], 'rows' => $rows];
    foreach ($c['s5']['statements'] as $qid => $s) {
        $r = $ratings[$qid];
        if ($r < 2) {
            continue;
        }
        $level = $r >= 3 ? $s['high'] : $s['mid'];
        $s5[] = wr_h($level['title']);
        foreach ($level['paras'] as $p) {
            $s5[] = wr_p(str_replace('[rating label]', wr_quote($c['s5']['labels'][$r]), $p));
        }
    }
    $zeros = array_keys(array_filter($ratings, static fn($r) => $r === 0));
    $ones = array_keys(array_filter($ratings, static fn($r) => $r === 1));
    if ($zeros !== [] || $ones !== []) {
        $s5[] = wr_h($c['s5']['less_heading']);
        if ($zeros !== []) {
            $s5[] = wr_p(str_replace('{list}', wr_join(array_map(static fn($q) => $c['s5']['statements'][$q]['fragment'], $zeros)), $c['s5']['zeros']));
        }
        if ($ones !== []) {
            $s5[] = wr_p(str_replace('{list}', wr_join(array_map(static fn($q) => $c['s5']['statements'][$q]['fragment'], $ones)), $c['s5']['ones']));
        }
    }
    $sections[5] = $s5;

    // 6 How you cope
    $q43 = wp_int($answers, '4.3', 0, 4);
    $s6 = [
        wr_p(strtr($c['s6']['opening'], [
            '{q41}' => wr_join(array_map(static fn($id) => WR_COPING_PHRASES[wr_letter($id)], $q41)),
            '{q43}' => $q43 . ' out of 4, where 0 is “it helps” and 4 is “it makes things worse”',
        ])),
        wr_p(str_replace('{q42}', wr_quote(WR_COPING_LABELS[wr_letter($q42)]), $c['s6']['context42'])),
        wr_p($c['s6']['helpful'][$q43 <= 1 ? 'low' : ($q43 === 2 ? 'mid' : 'high')]),
    ];
    $responses = [];
    foreach (WR_COPING_LABELS as $letter => $_) {
        $in41 = in_array('4.1.' . $letter, $q41, true);
        $in42 = $q42 === '4.2.' . $letter;
        if ($in41 || $in42) {
            $responses[$letter] = [$in41, $in42];
        }
    }
    foreach ($responses as $letter => [$in41, $in42]) {
        $key = '4.1.' . $letter;
        $s6[] = wr_h(WR_COPING_LABELS[$letter]);
        if ($in41 && $in42) {
            $s6[] = wr_note('You chose this for when things get tough and for when things become especially difficult.');
        } elseif ($in42) {
            $s6[] = wr_note('You chose this for when things become especially difficult.');
        }
        $s6[] = wr_p($support['restricted'] && isset($c['restricted']['s6_' . $key]) ? $c['restricted']['s6_' . $key] : $c['s6']['coping'][$key]);
    }
    $s6[] = wr_p($plain ? $c['s6']['closing']['neutral'] : $c['s6']['closing'][$primary]);
    $sections[6] = $s6;

    // 7 What is on your plate
    $areas = array_values(array_filter(wp_choices($answers, '1.4'), static fn($id) => isset(WR_LIFE_AREA_PHRASES[wr_letter($id)]) && $id !== '1.4.k'));
    $q12 = wp_int($answers, '1.2', 0, 10);
    $s7 = [];
    if ($areas !== []) {
        $s7[] = wr_p(strtr($c['s7']['opening'], [
            '{areas}' => wr_join(array_map(static fn($id) => WR_LIFE_AREA_PHRASES[wr_letter($id)], $areas)),
            '{isare}' => count($areas) === 1 ? 'is' : 'are',
        ]));
    }
    $s7[] = wr_p(str_replace('{n}', (string) $q12, $c['s7']['impact']));
    if (wp_recent_change($answers)) {
        $s7[] = wr_p($c['s7']['recent']);
    }
    $s7[] = wr_p($c['s7'][$q12 >= 8 ? 'impact_high' : ($q12 >= 4 ? 'impact_mid' : 'impact_low')]);
    foreach ($areas as $id) {
        $s7[] = wr_h($c['s7']['areas'][$id]['heading']);
        $s7[] = wr_p($c['s7']['areas'][$id]['text']);
    }
    $sections[7] = $s7;

    // 8 What helps you
    $s8 = [];
    if ($support['unsafe']) {
        $s8[] = wr_p($c['s8']['unsafe']);
        $s8[] = ['type' => 'links', 'items' => $c['s10']['urgent_links']];
    } elseif ($support['unavailable']) {
        $s8[] = wr_p($c['s8']['unavailable']);
    } else {
        $prefs = array_values(array_filter(wp_choices($answers, '5.1'), static fn($id) => isset($c['s8']['prefs'][$id])));
        if ($prefs !== []) {
            $s8[] = wr_p(str_replace('{prefs}', wr_join(array_map(static fn($id) => WR_SUPPORT_PHRASES[wr_letter($id)], $prefs)), $c['s8']['opening']));
            foreach ($prefs as $id) {
                $s8[] = wr_h($c['s8']['prefs'][$id]['heading']);
                $s8[] = wr_p($c['s8']['prefs'][$id]['text']);
            }
        }
    }
    [$recharge, $settle] = wr_activity_groups($answers, $support['restricted']);
    $s8[] = wr_h($c['s8']['recharge_heading']);
    $s8[] = wr_p($c['s8']['recharge'][$recharge]);
    $s8[] = wr_h($c['s8']['settle_heading']);
    $s8[] = wr_p($c['s8']['settle'][$settle]);
    $sections[8] = $s8;

    // 10 Your Tough Day Guide
    $s10 = array_map('wr_p', $c['s10']['opening']);
    $s10[] = wr_h($c['s10']['step1_heading']);
    $s10[] = wr_p($paired ? $c['s10']['step1'][$secondaryId] : $c['s10']['step1']['neutral']);
    if (in_array('4.1.l', $q41, true) || $q42 === '4.2.l') {
        $s10[] = wr_p($c['s10']['pressure']);
    }
    $s10[] = wr_h($c['s10']['step2_heading']);
    $activity = wr_most_helpful_activity($answers);
    if ($activity === null) {
        $s10[] = wr_p($c['s10']['step2_none']);
    } elseif ($support['restricted'] && in_array($activity, ['6.5.b', '6.5.m'], true)) {
        $s10[] = wr_p($c['s10']['step2_contact_excluded']);
    } else {
        $s10[] = wr_p($c['s10']['step2'][$activity]);
    }
    $s10[] = wr_h($c['s10']['step3_heading']);
    $s10[] = wr_p($support['restricted'] ? $c['restricted']['s10_step3'] : $c['s10']['step3']);
    $s10[] = wr_p($paired ? $c['s10']['closing'][$secondaryId] : $c['s10']['closing']['neutral']);
    $s10[] = wr_h($c['s10']['urgent_heading']);
    $s10[] = wr_p($c['s10']['urgent'][0]);
    $s10[] = ['type' => 'links', 'items' => $c['s10']['urgent_links']];
    $s10[] = wr_p($c['s10']['urgent'][1]);
    $sections[10] = $s10;

    // 12 About this report (sections are renumbered on screen, so name Section 10 by its title)
    $sections[12] = array_map(static fn($p) => wr_p(str_replace('in Section 10.', 'in ' . $c['titles'][10] . '.', $p)), $c['s12']['paras']);

    ksort($sections);
    $out = [];
    $number = 0;
    foreach ($sections as $id => $blocks) {
        $out[] = [
            'id' => $id,
            'number' => ++$number,
            'group' => WR_GROUPS[$id],
            'title' => $id === 2 && $plain ? 'Understanding your responses' : $c['titles'][$id],
            'blocks' => $blocks,
        ];
    }

    return [
        'source' => $c['source'],
        'score_version' => WP_SCORE_VERSION,
        'language' => $plain ? 'plain' : 'characters',
        'primary' => ['id' => $primary, 'title' => WR_TITLES[$primary][$plain ? 1 : 0]],
        'support_first' => $support['unsafe'] ? [
            wr_p($c['s8']['unsafe']),
            wr_p($c['s10']['urgent'][0]),
            ['type' => 'links', 'items' => $c['s10']['urgent_links']],
        ] : null,
        'question' => $question,
        'sections' => $out,
        'section_count' => count($out),
    ];
}

/** The single most-helpful named activity: 6.5b, or the only named one ticked. */
function wr_most_helpful_activity(array $answers): ?string
{
    $named = array_values(array_filter(wp_choices($answers, '6.5'), static fn($id) => is_string($id) && preg_match('/^6\.5\.[a-m]$/', $id)));
    $chosen = wp_single($answers, '6.5b');
    if ($chosen !== null && in_array($chosen, $named, true)) {
        return $chosen;
    }
    return count($named) === 1 ? $named[0] : null;
}

/**
 * Recharge and settling versions (Appendix A mappings). Unsafe or unavailable support
 * removes Trusted person and Family from both mappings.
 * @return array{0: string, 1: string}
 */
function wr_activity_groups(array $answers, bool $restricted): array
{
    $eligible = array_values(array_filter(
        wp_choices($answers, '6.5'),
        static fn($id) => is_string($id) && preg_match('/^6\.5\.[a-m]$/', $id) && !($restricted && in_array($id, ['6.5.b', '6.5.m'], true))
    ));
    $letters = array_map('wr_letter', $eligible);

    $alone = array_intersect($letters, ['a', 'g', 'f', 'l']) !== [];
    $people = array_intersect($letters, ['b', 'm']) !== [];
    $recharge = $alone && $people ? 'mix' : ($alone ? 'alone' : ($people ? 'people' : 'depends'));

    $groups = ['movement' => ['c', 'd'], 'still' => ['g', 'l', 'a', 'e'], 'hands' => ['h', 'j', 'f'], 'talking' => ['b', 'm']];
    $settle = 'none';
    $most = wr_most_helpful_activity($answers);
    if ($most !== null && in_array($most, $eligible, true)) {
        foreach ($groups as $group => $members) {
            if (in_array(wr_letter($most), $members, true)) {
                $settle = $group;
            }
        }
    }
    if ($settle === 'none') {
        $counts = array_map(static fn($members) => count(array_intersect($letters, $members)), $groups);
        $max = max($counts);
        $leaders = array_keys($counts, $max, true);
        if ($max > 0 && count($leaders) === 1) {
            $settle = $leaders[0];
        }
    }
    return [$recharge, $settle];
}

// ─────────────────────────────────────────────────────────────────────────────
// Saved runs and purchases (database)
// ─────────────────────────────────────────────────────────────────────────────

function wr_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS wellbeing_profile_runs (
            id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id                    INT UNSIGNED    NOT NULL,
            answers_json               TEXT            NOT NULL,
            score_version              VARCHAR(64)     NOT NULL,
            accepted_primary           VARCHAR(4)      NOT NULL,
            language                   ENUM('characters','plain') NOT NULL DEFAULT 'characters',
            secondary_selected         VARCHAR(4)          NULL DEFAULT NULL,
            secondary_response         VARCHAR(16)         NULL DEFAULT NULL,
            secondary_answered_at      DATETIME            NULL DEFAULT NULL,
            stripe_checkout_session_id VARCHAR(128)        NULL DEFAULT NULL,
            stripe_payment_intent_id   VARCHAR(64)         NULL DEFAULT NULL,
            amount_cents               INT UNSIGNED        NULL DEFAULT NULL,
            currency                   CHAR(3)             NULL DEFAULT NULL,
            purchased_at               DATETIME            NULL DEFAULT NULL,
            created_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wpr_checkout_session (stripe_checkout_session_id),
            KEY idx_wpr_user_purchased (user_id, purchased_at),
            KEY idx_wpr_unpurchased (purchased_at, created_at),
            CONSTRAINT fk_wpr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

/** Keep only the answers the report needs, with simple shape checks. */
function wr_clean_answers(array $answers): array
{
    $clean = [];
    foreach (WR_STORED_QUESTIONS as $id) {
        if (!array_key_exists($id, $answers)) {
            continue;
        }
        $v = $answers[$id];
        if (is_array($v)) {
            $v = array_values(array_filter($v, static fn($x) => is_string($x) && strlen($x) <= 12));
        } elseif (!is_int($v) && !(is_string($v) && strlen($v) <= 12)) {
            continue;
        }
        $clean[$id] = $v;
    }
    return $clean;
}

/**
 * Score the answers again on the server and save a run for the buyer to pay for.
 * The accepted primary must be one the answers allow. Throws WellbeingScoringError.
 */
function wr_save_run(int $userId, array $answers, string $acceptedPrimary, string $language, bool $safetyConfirmed): int
{
    $answers = wr_clean_answers($answers);
    $result = wp_score_questionnaire($answers);
    if ($result['safety']['safety_check_required'] && !$safetyConfirmed) {
        throw new WellbeingScoringError('The safety check needs to be answered first.');
    }
    $selection = wp_select_primary($result['primary'], $acceptedPrimary, $language);
    if ($selection['status'] !== 'accepted') {
        throw new WellbeingScoringError('The full report needs a description you have confirmed.');
    }

    wr_ensure_table();
    // Non-buyers' answers are not kept (master §12).
    db()->exec('DELETE FROM wellbeing_profile_runs WHERE purchased_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ' . WR_UNPURCHASED_RETENTION_DAYS . ' DAY)');

    db()->prepare(
        'INSERT INTO wellbeing_profile_runs (user_id, answers_json, score_version, accepted_primary, language)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, json_encode($answers), WP_SCORE_VERSION, $acceptedPrimary, $language]);
    return (int) db()->lastInsertId();
}

function wr_find_run(int $runId, int $userId): ?array
{
    wr_ensure_table();
    $stmt = db()->prepare('SELECT * FROM wellbeing_profile_runs WHERE id = ? AND user_id = ?');
    $stmt->execute([$runId, $userId]);
    return $stmt->fetch() ?: null;
}

function wr_latest_purchased_run(int $userId): ?array
{
    wr_ensure_table();
    $stmt = db()->prepare('SELECT * FROM wellbeing_profile_runs WHERE user_id = ? AND purchased_at IS NOT NULL ORDER BY purchased_at DESC, id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function wr_report_for_run(array $run): array
{
    $report = wr_build_report(
        json_decode((string) $run['answers_json'], true) ?: [],
        (string) $run['accepted_primary'],
        (string) $run['language'],
        $run['secondary_selected'] ?: null,
        $run['secondary_response'] ?: null
    );
    $report['run_id'] = (int) $run['id'];
    $report['purchased_at'] = $run['purchased_at'];
    return $report;
}

function wr_save_secondary_answer(array $run, ?string $selected, string $response): void
{
    $answers = json_decode((string) $run['answers_json'], true) ?: [];
    $state = wr_secondary_state($answers, (string) $run['accepted_primary'], $run['secondary_selected'] ?: null, $run['secondary_response'] ?: null);
    wr_validate_secondary_answer($state, $selected, $response);
    db()->prepare(
        'UPDATE wellbeing_profile_runs SET secondary_selected = ?, secondary_response = ?, secondary_answered_at = NOW()
         WHERE id = ? AND secondary_response IS NULL'
    )->execute([$selected, $response, (int) $run['id']]);
}

function wr_save_language(array $run, string $language): void
{
    if (!in_array($language, ['characters', 'plain'], true)) {
        throw new WellbeingScoringError('Language preference must be "characters" or "plain".');
    }
    db()->prepare('UPDATE wellbeing_profile_runs SET language = ? WHERE id = ?')->execute([$language, (int) $run['id']]);
}

/**
 * Record a paid report. Idempotent (webhook retries, and the return page may get there first).
 * @return bool true when this call marked it paid
 */
function wr_mark_paid(int $runId, int $userId, string $checkoutSessionId, ?string $paymentIntentId, int $amountCents, string $currency): bool
{
    wr_ensure_table();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, purchased_at FROM wellbeing_profile_runs WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$runId, $userId]);
        $run = $stmt->fetch();
        if (!$run) {
            $pdo->rollBack();
            error_log("wellbeing report: paid session {$checkoutSessionId} names run {$runId}, which user {$userId} does not have");
            return false;
        }
        if ($run['purchased_at'] !== null) {
            $pdo->commit();
            return false;
        }
        $pdo->prepare(
            'UPDATE wellbeing_profile_runs
             SET purchased_at = NOW(), stripe_checkout_session_id = ?, stripe_payment_intent_id = ?, amount_cents = ?, currency = ?
             WHERE id = ?'
        )->execute([$checkoutSessionId, $paymentIntentId, $amountCents, strtolower($currency), $runId]);
        // One-off payments create no invoice, so record the payment here for billing history.
        $pdo->prepare(
            "INSERT INTO payments (user_id, subscription_id, stripe_payment_intent_id, amount_cents, currency, status, description)
             VALUES (?, NULL, ?, ?, ?, 'succeeded', ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)"
        )->execute([$userId, $paymentIntentId, $amountCents, strtolower($currency), WR_PRODUCT_NAME]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    try {
        require_once __DIR__ . '/mailer.php';
        $contact = db()->prepare('SELECT email, full_name FROM users WHERE id = ?');
        $contact->execute([$userId]);
        $user = $contact->fetch();
        if ($user) {
            send_wellbeing_report_email((string) $user['email'], $user['full_name'] ?? null);
        }
    } catch (Throwable $e) {
        error_log('wellbeing report email failed for user ' . $userId . ': ' . $e->getMessage());
    }
    return true;
}

/**
 * Check a Checkout Session for this product and record the purchase if it is paid.
 * Used by the webhook and by the page the buyer returns to.
 * @return ?int the run id, or null when the session is not a paid report for this user
 */
function wr_confirm_checkout_session(object $session, int $userId): ?int
{
    $meta = $session->metadata ?? null;
    $productKey = (string) ($meta['product_key'] ?? '');
    $metaUser = (int) ($meta['user_id'] ?? 0);
    $runId = (int) ($meta['run_id'] ?? 0);
    if ($productKey !== WR_PRODUCT_KEY || $metaUser !== $userId || $runId <= 0) {
        return null;
    }
    if (($session->mode ?? '') !== 'payment' || ($session->payment_status ?? '') !== 'paid') {
        return null;
    }
    wr_mark_paid(
        $runId,
        $userId,
        (string) $session->id,
        is_string($session->payment_intent ?? null) ? $session->payment_intent : null,
        (int) ($session->amount_total ?? WR_PRICE_CENTS),
        (string) ($session->currency ?? 'aud')
    );
    return $runId;
}
