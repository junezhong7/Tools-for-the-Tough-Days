<?php
/**
 * Tools for the Tough Days — Wellbeing Profile ("My Imprint") scoring engine
 *
 * Source of the rules: "My_Imprint_Consolidated_Master_for_June_v4" §4–5 and
 * "My_Imprint_Approved_Scoring_Decisions_v4" (both 2 Nov 2026, approved by Nic), with one
 * site decision (19 Nov 2026, June): the two scored options of the OLD question 1.3
 * ("To feel more in control" TF +1, "Something to use when I can't talk to anyone" BS +2)
 * are dropped, because 1.3 is now the goals question. Maxima follow the remaining items:
 * TF 17 (was 18), BS 16 (was 18). Everything else is unchanged.
 *
 * Pure functions only: no database, no session, no rendering. Answers use the option ids
 * of the questionnaire in wellbeing-profile.html (e.g. '4.1' => ['4.1.a', '4.1.h']).
 *
 * Numbers: answers can earn half points (1.1 counts half when 1.5 is Yes), so scores are
 * kept as exact fractions and percentages are compared before any rounding.
 */

declare(strict_types=1);

const WP_SCORE_VERSION = 'v4-2026-11-02+drop-old-1.3';

/** Fixed maxima (sum of each pattern's items), used for every participant. */
const WP_PATTERN_MAXIMA = ['TH' => 22, 'TF' => 17, 'BS' => 16, 'BP' => 29];

/** Registry order: stable presentation order only, never a tie-break. */
const WP_PATTERN_ORDER = ['TH', 'TF', 'BS', 'BP'];

const WP_PRIMARY_WINDOW_POINTS = 5;   // primary near-tie window, percentage points, inclusive
const WP_WEAK_SECONDARY_BELOW = 25;   // score-derived secondary below this % needs an explicit yes

/**
 * Scoring tables (master §4). 'scale' items multiply the 0–4 statement value;
 * 'option' items add points when that option is chosen. Items from 1.1 are halved when 1.5 is Yes.
 */
const WP_SCORING = [
    'TH' => [
        ['scale', '3.1', 2], ['scale', '3.2', 1],
        ['option', '1.1', '1.1.a', 2],   // Feeling flat or low
        ['option', '1.1', '1.1.e', 2],   // Feeling disconnected from people
        ['option', '1.1', '1.1.h', 1],   // Being hard on yourself
        ['option', '1.1', '1.1.f', 1],   // No motivation or energy
        ['option', '1.4', '1.4.e', 1],   // Loss or grief
        ['option', '1.4', '1.4.f', 1],   // Loneliness
        ['option', '4.1', '4.1.k', 2],   // Look for comfort or reassurance
    ],
    'TF' => [
        ['scale', '3.3', 2],
        ['option', '1.1', '1.1.c', 2],   // Feeling on edge or irritable
        ['option', '4.1', '4.1.i', 3],   // Act first, think later
        ['option', '4.1', '4.1.h', 1],   // Get short or snappy with people
        ['option', '4.4', '4.4.a', 3],   // It bursts out before I can stop it
        ['option', '4.4', '4.4.d', 1],   // A bit of all of these
        // 4.4.e "doesn't usually spill over": no points. Old 1.3 "To feel more in control" +1: dropped.
    ],
    'BS' => [
        ['scale', '3.1', 1],
        ['option', '4.1', '4.1.b', 3],   // Withdraw and go quiet
        ['option', '4.1', '4.1.f', 3],   // Try to numb it out
        ['option', '4.1', '4.1.c', 2],   // Distract yourself
        ['option', '5.1', '5.1.c', 2],   // Being left to work it out alone
        ['option', '5.1', '5.1.g', 1],   // Not being a burden to anyone
        ['option', '1.1', '1.1.e', 1],   // Feeling disconnected from people
        // Old 1.3 "Something to use when I can't talk to anyone" +2: dropped.
    ],
    'BP' => [
        ['scale', '3.5', 2], ['scale', '3.4', 1],
        ['option', '4.1', '4.1.a', 3],   // Push through and keep busy
        ['option', '4.1', '4.1.d', 2],   // Get hard on yourself
        ['option', '4.1', '4.1.g', 2],   // Overthink and analyse
        ['option', '4.1', '4.1.h', 1],   // Get short or snappy with people
        ['option', '1.1', '1.1.h', 2],   // Being hard on yourself
        ['option', '1.1', '1.1.b', 1],   // Worry or racing thoughts
        ['option', '1.1', '1.1.g', 1],   // Feeling overwhelmed
        ['option', '4.4', '4.4.b', 3],   // It builds up for a while, then I snap
        ['option', '4.4', '4.4.c', 3],   // I'm standing my ground or protecting something that matters
        ['option', '4.4', '4.4.d', 1],   // A bit of all of these
        ['option', '4.1', '4.1.l', 2],   // Put pressure on myself to get everything right (+2 once)
    ],
];

/** 4.2 direct secondary mapping (master §4). 'A' is the single generic Adult secondary. */
const WP_DIRECT_SECONDARY = [
    '4.2.a' => 'BP', '4.2.d' => 'BP', '4.2.g' => 'BP', '4.2.l' => 'BP',
    '4.2.b' => 'BS', '4.2.c' => 'BS', '4.2.f' => 'BS',
    '4.2.i' => 'TF',
    '4.2.k' => 'TH',
    '4.2.e' => 'A', '4.2.j' => 'A',
    // '4.2.h' (Get short or snappy) is settled by 4.4, see wp_resolve_secondary().
];

final class WellbeingScoringError extends InvalidArgumentException {}

// ─────────────────────────────────────────────────────────────────────────────
// Exact fractions [numerator, denominator] (non-negative, denominator > 0)
// ─────────────────────────────────────────────────────────────────────────────

/** Parse a non-negative decimal (int, float-free string like "17.1") into a fraction. */
function wp_decimal(int|string $value): array
{
    $text = (string) $value;
    if (!preg_match('/^\d+(?:\.(\d{1,6}))?$/', $text, $m)) {
        throw new WellbeingScoringError("Earned totals must be non-negative decimals with at most 6 places, got '{$text}'.");
    }
    $places = isset($m[1]) ? strlen($m[1]) : 0;
    return [(int) str_replace('.', '', $text), 10 ** $places];
}

/** Percentage of a fixed maximum, as a fraction: 100 * score / maximum. */
function wp_percent(array $score, int $maximum): array
{
    return [$score[0] * 100, $score[1] * $maximum];
}

function wp_cmp(array $a, array $b): int
{
    return $a[0] * $b[1] <=> $b[0] * $a[1];
}

/** Is $candidate at most $points percentage points below $top (inclusive)? */
function wp_within_points(array $top, array $candidate, int $points): bool
{
    return $top[0] * $candidate[1] - $candidate[0] * $top[1] <= $points * $top[1] * $candidate[1];
}

function wp_fraction_to_float(array $f): float
{
    return $f[0] / $f[1];
}

// ─────────────────────────────────────────────────────────────────────────────
// Answers
// ─────────────────────────────────────────────────────────────────────────────

function wp_choices(array $answers, string $id): array
{
    $v = $answers[$id] ?? [];
    return is_array($v) ? array_values($v) : [$v];
}

function wp_single(array $answers, string $id): ?string
{
    $v = $answers[$id] ?? null;
    return is_string($v) && $v !== '' ? $v : null;
}

function wp_int(array $answers, string $id, int $min, int $max): int
{
    $v = $answers[$id] ?? null;
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
        throw new WellbeingScoringError("Answer {$id} is required.");
    }
    $v = (int) $v;
    if ($v < $min || $v > $max) {
        throw new WellbeingScoringError("Answer {$id} must be between {$min} and {$max}.");
    }
    return $v;
}

/** 0–4 statement value from an option id like '3.1.3' (or a bare integer). */
function wp_scale(array $answers, string $id): int
{
    $v = $answers[$id] ?? null;
    if (is_string($v) && preg_match('/^' . preg_quote($id, '/') . '\.([0-4])$/', $v, $m)) {
        return (int) $m[1];
    }
    return wp_int($answers, $id, 0, 4);
}

/** 4.4 display condition (questionnaire): 1.1 on edge, 4.1 snappy/act first, or 4.2 snappy. */
function wp_shows_4_4(array $answers): bool
{
    return in_array('1.1.c', wp_choices($answers, '1.1'), true)
        || array_intersect(['4.1.h', '4.1.i'], wp_choices($answers, '4.1')) !== []
        || wp_single($answers, '4.2') === '4.2.h';
}

function wp_recent_change(array $answers): bool
{
    // 1.5 is only asked when a life area is ticked in 1.4.
    return wp_choices($answers, '1.4') !== [] && wp_single($answers, '1.5') === '1.5.a';
}

/** Checks the answers every result depends on. Throws with the first missing one. */
function wp_validate_answers(array $answers): void
{
    foreach (['1.1', '4.1'] as $multi) {
        if (wp_choices($answers, $multi) === []) {
            throw new WellbeingScoringError("Answer {$multi} is required.");
        }
    }
    wp_int($answers, '1.2', 0, 10);
    wp_int($answers, '4.3', 0, 4);
    foreach (['3.1', '3.2', '3.3', '3.4', '3.5'] as $s) {
        wp_scale($answers, $s);
    }
    if (wp_single($answers, '4.2') === null) {
        throw new WellbeingScoringError('Answer 4.2 is required.');
    }
    if (wp_choices($answers, '1.4') !== [] && wp_single($answers, '1.5') === null) {
        throw new WellbeingScoringError('Answer 1.5 is required when a life area is selected in 1.4.');
    }
    // A missing 4.4 is NOT an error here: the secondary rules treat it as unresolved.
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 1–2: pattern scores (with the recent-change adjustment)
// ─────────────────────────────────────────────────────────────────────────────

/** @return array<string, array{0:int,1:int}> earned points per pattern as fractions */
function wp_pattern_scores(array $answers): array
{
    $halfOneOne = wp_recent_change($answers);
    $scores = [];
    foreach (WP_SCORING as $pattern => $items) {
        $halfPoints = 0; // work in half points so halving stays exact
        foreach ($items as $item) {
            if ($item[0] === 'scale') {
                $halfPoints += 2 * wp_scale($answers, $item[1]) * $item[2];
                continue;
            }
            [, $question, $option, $points] = $item;
            $chosen = $question === '4.4' || $question === '4.2'
                ? wp_single($answers, $question) === $option
                : in_array($option, wp_choices($answers, $question), true);
            if (!$chosen) {
                continue;
            }
            if ($question === '4.4' && !wp_shows_4_4($answers)) {
                continue; // a hidden follow-up earns nothing; the maximum stays fixed
            }
            $halfPoints += ($question === '1.1' && $halfOneOne) ? $points : 2 * $points;
        }
        $scores[$pattern] = [$halfPoints, 2];
    }
    return $scores;
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 3: is the Adult driving?
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{eligible: bool, type: ?string, failed: string[]} */
function wp_adult_check(array $answers, array $scores): array
{
    $recent = wp_recent_change($answers);
    $interference = wp_int($answers, '1.2', 0, 10);
    $working = wp_int($answers, '4.3', 0, 4);
    $limit = array_intersect(['4.1.e', '4.1.j'], wp_choices($answers, '4.1')) !== [] ? 45 : 35;

    $failed = [];
    foreach (WP_PATTERN_ORDER as $p) {
        if (wp_cmp(wp_percent($scores[$p], WP_PATTERN_MAXIMA[$p]), [$limit, 1]) >= 0) {
            $failed[] = "pattern_{$p}_at_or_above_{$limit}";
        }
    }
    if (!$recent && $interference > 5) {
        $failed[] = 'interference_above_5';
    }
    if ($working > 2) {
        $failed[] = 'coping_not_working';
    }
    // Hard stops (lifted with the interference condition when 1.5 is Yes).
    if (!$recent && ($interference >= 8 || $working === 4)) {
        $failed[] = 'hard_stop';
    }
    if ($failed !== []) {
        return ['eligible' => false, 'type' => null, 'failed' => $failed];
    }

    $stretched = count(wp_choices($answers, '1.4')) >= 2
        || in_array('1.1.g', wp_choices($answers, '1.1'), true)
        || in_array($interference, [4, 5], true)
        || $recent;
    return ['eligible' => true, 'type' => $stretched ? 'AS' : 'AD', 'failed' => []];
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 4: primary candidates and acceptance (mirrors primary-choice.mjs)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string,int|string> $earned  earned totals per pattern (exact decimals)
 * @return array{kind:string, candidates:string[], adult_type?:string, requires_acceptance:bool}
 */
function wp_resolve_primary(bool $adultEligible, array $earned, ?string $adultType = null): array
{
    if ($adultEligible) {
        if (!in_array($adultType, ['AS', 'AD'], true)) {
            throw new WellbeingScoringError('Adult route needs its type (AS or AD).');
        }
        return ['kind' => 'adult', 'candidates' => [$adultType], 'adult_type' => $adultType, 'requires_acceptance' => true];
    }

    $percent = [];
    foreach (WP_PATTERN_ORDER as $p) {
        if (!array_key_exists($p, $earned)) {
            throw new WellbeingScoringError("All four pattern totals are required (missing {$p}).");
        }
        $score = is_array($earned[$p]) ? $earned[$p] : wp_decimal($earned[$p]);
        if (wp_cmp($score, [WP_PATTERN_MAXIMA[$p], 1]) > 0) {
            throw new WellbeingScoringError("{$p} total exceeds its fixed maximum.");
        }
        $percent[$p] = wp_percent($score, WP_PATTERN_MAXIMA[$p]);
    }

    $top = null;
    foreach ($percent as $f) {
        if ($top === null || wp_cmp($f, $top) > 0) {
            $top = $f;
        }
    }
    if ($top[0] === 0) {
        return ['kind' => 'no_supported_pattern', 'candidates' => [], 'requires_acceptance' => true];
    }

    $candidates = [];
    foreach (WP_PATTERN_ORDER as $p) {
        if ($percent[$p][0] > 0 && wp_within_points($top, $percent[$p], WP_PRIMARY_WINDOW_POINTS)) {
            $candidates[] = $p;
        }
    }
    return [
        'kind' => count($candidates) > 1 ? 'participant_choice_required' : 'single_scored_candidate',
        'candidates' => $candidates,
        'requires_acceptance' => true,
    ];
}

/**
 * Participant's answer to the primary result. $selected null = "None of these feels right".
 * Language preference is saved separately and never implies acceptance.
 */
function wp_select_primary(array $resolution, ?string $selected, string $languagePreference): array
{
    if (!in_array($resolution['kind'] ?? '', ['adult', 'participant_choice_required', 'single_scored_candidate'], true)) {
        throw new WellbeingScoringError('A current primary resolution is required.');
    }
    if (!in_array($languagePreference, ['characters', 'plain'], true)) {
        throw new WellbeingScoringError('Language preference must be "characters" or "plain".');
    }
    if ($selected === null) {
        return ['status' => 'unconfirmed', 'accepted_primary' => null, 'language' => $languagePreference, 'driver_journey_allowed' => false];
    }
    if (!in_array($selected, $resolution['candidates'], true)) {
        throw new WellbeingScoringError("'{$selected}' is not an eligible primary for these answers.");
    }
    return ['status' => 'accepted', 'accepted_primary' => $selected, 'language' => $languagePreference, 'driver_journey_allowed' => true];
}

// ─────────────────────────────────────────────────────────────────────────────
// Secondary driver (resolved after the primary is accepted; revealed only after payment)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Highest score-derived alternative to the primary (patterns only). No five-point window:
 * only exactly tied highest alternatives are offered together.
 */
function wp_secondary_fallback(array $scores, string $primary, string $reason): array
{
    $alternatives = array_values(array_filter(WP_PATTERN_ORDER, fn($p) => $p !== $primary));
    $top = null;
    foreach ($alternatives as $p) {
        $f = wp_percent($scores[$p], WP_PATTERN_MAXIMA[$p]);
        if ($top === null || wp_cmp($f, $top) > 0) {
            $top = $f;
        }
    }
    if ($top === null || $top[0] === 0) {
        return ['kind' => 'none', 'reason' => 'all_alternatives_zero', 'content' => 'primary_only'];
    }
    $tied = array_values(array_filter($alternatives, fn($p) => wp_cmp(wp_percent($scores[$p], WP_PATTERN_MAXIMA[$p]), $top) === 0));
    $weak = wp_cmp($top, [WP_WEAK_SECONDARY_BELOW, 1]) < 0;
    if (count($tied) > 1) {
        return ['kind' => 'choice', 'source' => 'fallback_tie', 'candidates' => $tied, 'weak' => $weak, 'reason' => $reason];
    }
    return ['kind' => 'fallback', 'secondary' => $tied[0], 'weak' => $weak, 'reason' => $reason];
}

/** Apply distinct-from-primary: a mapped secondary equal to the primary falls back. */
function wp_distinct_or_fallback(string $mapped, array $scores, string $primary, string $source): array
{
    $isAdultPrimary = in_array($primary, ['AS', 'AD'], true);
    if ($mapped === $primary || ($mapped === 'A' && $isAdultPrimary)) {
        return wp_secondary_fallback($scores, $primary, 'direct_matches_primary');
    }
    return ['kind' => 'direct', 'secondary' => $mapped, 'source' => $source];
}

/**
 * Secondary resolution for an ACCEPTED primary.
 * kinds: direct | fallback (weak flag) | choice (needs a pick, "Neither / I'm not sure" allowed)
 *        | none (primary-only) | unresolved (missing follow-up: offer review, primary-only meanwhile)
 */
function wp_resolve_secondary(array $answers, string $acceptedPrimary, ?array $scores = null): array
{
    if (!in_array($acceptedPrimary, ['TH', 'TF', 'BS', 'BP', 'AS', 'AD'], true)) {
        throw new WellbeingScoringError('A secondary needs an accepted primary.');
    }
    // $scores is injectable for tests; production always derives it from the answers.
    $scores = $scores ?? wp_pattern_scores($answers);
    $q42 = wp_single($answers, '4.2');
    if ($q42 === null) {
        return ['kind' => 'unresolved', 'reason' => 'missing_4.2', 'content' => 'primary_only'];
    }

    if ($q42 !== '4.2.h') {
        if (!isset(WP_DIRECT_SECONDARY[$q42])) {
            throw new WellbeingScoringError("Unknown 4.2 answer '{$q42}'.");
        }
        return wp_distinct_or_fallback(WP_DIRECT_SECONDARY[$q42], $scores, $acceptedPrimary, '4.2');
    }

    // "Get short or snappy with people": settled by 4.4.
    $q44 = wp_single($answers, '4.4');
    switch ($q44) {
        case null:
            return ['kind' => 'unresolved', 'reason' => 'missing_4.4', 'content' => 'primary_only'];
        case '4.4.a':
            return wp_distinct_or_fallback('TF', $scores, $acceptedPrimary, '4.2+4.4');
        case '4.4.b':
        case '4.4.c':
            return wp_distinct_or_fallback('BP', $scores, $acceptedPrimary, '4.2+4.4');
        case '4.4.e':
            return ['kind' => 'none', 'reason' => 'frustration_does_not_spill_over', 'content' => 'primary_only'];
        case '4.4.d':
            // Mixed: the higher of the two normalised scores.
            $tf = wp_percent($scores['TF'], WP_PATTERN_MAXIMA['TF']);
            $bp = wp_percent($scores['BP'], WP_PATTERN_MAXIMA['BP']);
            if ($tf[0] === 0 && $bp[0] === 0) {
                return ['kind' => 'none', 'reason' => 'mixed_frustration_both_zero', 'content' => 'primary_only'];
            }
            $c = wp_cmp($tf, $bp);
            if ($c === 0) {
                return ['kind' => 'choice', 'source' => 'mixed_frustration_tie', 'candidates' => ['TF', 'BP'], 'weak' => false, 'reason' => 'tf_bp_equal'];
            }
            return wp_distinct_or_fallback($c > 0 ? 'TF' : 'BP', $scores, $acceptedPrimary, '4.2+4.4');
        default:
            throw new WellbeingScoringError("Unknown 4.4 answer '{$q44}'.");
    }
}

/**
 * Participant's answer about the secondary, after paid access.
 * $selected: candidate id for a 'choice' (null = "Neither / I'm not sure"); ignored otherwise.
 * $response: 'fits' | 'no' | 'unsure' | 'skip' | null (not answered yet = pending).
 * Saved states stay distinct even though all non-confirmed ones use primary-only content.
 */
function wp_select_secondary(array $resolution, string $acceptedPrimary, array $answers, ?string $selected, ?string $response): array
{
    $primaryOnly = static fn(string $status, ?string $candidate = null) => [
        'status' => $status, 'secondary' => null, 'candidate' => $candidate,
        'content' => 'primary_only', 'route' => $acceptedPrimary . '_NONE',
    ];
    $paired = static fn(string $status, string $secondary) => [
        'status' => $status, 'secondary' => $secondary, 'candidate' => $secondary,
        'content' => 'paired', 'route' => $acceptedPrimary . '_' . $secondary,
    ];

    switch ($resolution['kind']) {
        case 'none':
            return $primaryOnly('none');
        case 'unresolved':
            return $primaryOnly('unresolved');
        case 'direct':
            return $paired('established', $resolution['secondary']);
        case 'fallback':
            if (!$resolution['weak']) {
                return $paired('established', $resolution['secondary']);
            }
            return match ($response) {
                'fits'   => $paired('confirmed', $resolution['secondary']),
                'no'     => $primaryOnly('declined', $resolution['secondary']),
                'unsure' => $primaryOnly('unsure', $resolution['secondary']),
                'skip'   => $primaryOnly('skipped', $resolution['secondary']),
                null     => $primaryOnly('pending', $resolution['secondary']),
                default  => throw new WellbeingScoringError("Unknown secondary response '{$response}'."),
            };
        case 'choice':
            if (!in_array($response, ['fits', 'no', 'unsure', 'skip', null], true)) {
                throw new WellbeingScoringError("Unknown secondary response '{$response}'.");
            }
            if ($response === null) {
                return $primaryOnly('pending');
            }
            if ($response === 'skip') {
                return $primaryOnly('skipped');
            }
            // Only a picked description with "This fits" sets a secondary; "Neither / I'm not sure"
            // (no pick) or a "no" keeps primary-only content.
            if ($response !== 'fits' || $selected === null) {
                return $primaryOnly($response === 'no' ? 'declined' : 'unsure', $selected);
            }
            if (!in_array($selected, $resolution['candidates'], true)) {
                throw new WellbeingScoringError("'{$selected}' was not offered as a secondary.");
            }
            if ($selected === $acceptedPrimary) {
                // Distinctness after the pick: re-enter the ranked fallback, never save a duplicate.
                return ['status' => 'reselect', 'next' => wp_secondary_fallback(wp_pattern_scores($answers), $acceptedPrimary, 'choice_matches_primary')];
            }
            // For a weak score-derived candidate, picking it with "This fits" is its confirmation.
            return $paired($resolution['weak'] ? 'confirmed' : 'established', $selected);
    }
    throw new WellbeingScoringError('Unknown secondary resolution.');
}

// ─────────────────────────────────────────────────────────────────────────────
// Safety (master §5): checked before any result, price or sales message
// ─────────────────────────────────────────────────────────────────────────────

function wp_safety(array $answers): array
{
    $interference = wp_int($answers, '1.2', 0, 10);
    $working = wp_int($answers, '4.3', 0, 4);
    $life = wp_choices($answers, '1.4');
    $coping = wp_choices($answers, '4.1');

    $triggers = [];
    if ($interference >= 9 && $working === 4) {
        $triggers[] = 'A_severe_impact_failing_coping';
    }
    if (wp_scale($answers, '3.3') === 4 && in_array('4.1.i', $coping, true) && $interference >= 8) {
        $triggers[] = 'B_fired_up_extreme';
    }
    if (in_array('1.4.j', $life, true) && wp_single($answers, '4.2') === '4.2.f') {
        $triggers[] = 'C_substances_main_coping';
    }
    if (in_array('1.4.b', $life, true) && in_array('1.4.j', $life, true) && $interference >= 8) {
        $triggers[] = 'D_compounding_load';
    }
    if (in_array('1.4.i', $life, true)) {
        $triggers[] = 'accident_or_frightening_event';
    }

    return [
        'safety_check_required' => $triggers !== [],   // ask "are you safe right now?" before results
        'triggers' => $triggers,
        // Not an acute-danger classification: its own saved state and support pathway.
        'support_concerns' => in_array('1.4.k', $life, true) ? ['unsafe_at_home_or_relationship'] : [],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Whole result
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Score a completed questionnaire. Returns safety, pattern scores, Adult check and the
 * primary resolution. The secondary is resolved separately with wp_resolve_secondary()
 * once a primary is accepted (and is only shown after paid access).
 */
function wp_score_questionnaire(array $answers): array
{
    wp_validate_answers($answers);
    $scores = wp_pattern_scores($answers);
    $adult = wp_adult_check($answers, $scores);

    $patterns = [];
    foreach (WP_PATTERN_ORDER as $p) {
        $patterns[$p] = [
            'earned' => wp_fraction_to_float($scores[$p]),
            'maximum' => WP_PATTERN_MAXIMA[$p],
            'percent' => round(wp_fraction_to_float(wp_percent($scores[$p], WP_PATTERN_MAXIMA[$p])), 2), // display/logging only
        ];
    }

    return [
        'score_version' => WP_SCORE_VERSION,
        'safety' => wp_safety($answers),
        'recent_change' => wp_recent_change($answers),
        'patterns' => $patterns,
        'adult' => $adult,
        'primary' => wp_resolve_primary($adult['eligible'], $scores, $adult['type']),
    ];
}
