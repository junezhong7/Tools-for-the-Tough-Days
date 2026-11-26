<?php
/**
 * Tools for the Tough Days — Wellbeing Profile full report: launch copy (report-only offer)
 *
 * Copied from Nic's reviewed launch sketch of 23 Nov 2026 (worked-report-sections.json): the report-only
 * Sections 9 and 11, the new Section 10 layout and the bridging sentences in Sections 6 and 8.
 * Example values were replaced by {placeholders}; the wording is otherwise unchanged.
 * Nic marks this as review copy, not final publication approval.
 */

declare(strict_types=1);

return [
    'source' => 'Wellbeing_Profile_Launch_Sketch_2026-11-23 worked-report-sections.json',
    's6_context' => 'When things become especially difficult, you selected {q42}. That is a separate context from your ordinary tough-moment responses; your overall helpfulness rating does not separately rate each response.',
    's6_closing_heading' => 'A starting point for your primary pattern',
    's8_activities_heading' => 'Activities you identified as helpful',
    's8_most' => 'Of these activities, you selected {activity} as most helpful. This gives you a starting point; it does not mean {short} will help in every situation.',
    's9' => [
        'opening' => 'Your report is a starting point for understanding yourself. You can explore it at your own pace, using the parts that feel relevant to your life right now.',
        'goals_heading' => 'The goals you selected',
        'time' => 'You said that {time} is a realistic amount of time most days. Treat that as a preference, not a requirement or a measure of success.',
        'blocks' => [
            [
                'Start with one part of your report',
                'Choose a section that feels familiar or useful. Think about an ordinary situation where you notice the response or difficulty it describes. You do not need to work on everything at once.',
            ],
            [
                'Choose something manageable to try',
                'Return to “What helps you” and consider one of the activities or forms of support you identified. Choose an option that fits your circumstances and the time you have available.',
            ],
            [
                'Notice what fits, and adjust',
                'Afterwards, consider what was useful, what was less useful and whether you would like to try it again or make a change. There is no requirement to feel better immediately or keep using an idea that does not suit you.',
            ],
            [
                'Keep a short reference for tougher moments',
                'The next section, “Your personal Tough-Day Guide”, brings together a few brief reminders to return to when things feel difficult.',
            ],
        ],
    ],
    's10' => [
        'opening' => 'This is your quick reference for a tough day: a few reminders drawn from the responses and helpful activities in this report. Use whichever part fits the moment; you do not have to work through every step.',
        'step1_heading' => '1. Notice what is happening',
        'step2_heading' => '2. Try one familiar small thing',
        'step2_heading_named' => '2. Try one familiar small thing: {short}',
        'time' => 'You selected {time} as a realistic amount of time. A shorter moment is also an option; you do not need to use all the time you have available.',
        'step3_heading' => '3. Know where to turn',
        'boundary' => 'This guide is an everyday wellbeing reference, not an emergency response or a clinical safety plan. If the situation needs urgent or specialist help, access that help without completing these steps.',
        'danger' => 'If you are in danger now or there is an emergency, call 000. The platform does not monitor your safety or provide an emergency response.',
        'crisis' => 'For crisis support, contact Lifeline on 13 11 14. You can also open the separate support options without completing this guide.',
    ],
    's11' => [
        'opening' => 'Your wellbeing is something you can keep understanding and supporting over time. You can return to your report when your circumstances or needs change, notice what still fits and consider what you would like to adjust.',
        'blocks1' => [
            'Keep what is useful',
            'You do not need a particular pattern of progress to make reflection worthwhile. You can build on what helps, revise a choice or leave an option that is not useful for you.',
        ],
        'prefs_heading' => 'Review your preferences',
        'prefs' => 'You chose {activity} as most helpful and {time} as a manageable amount of time. Those choices can be reconsidered if they no longer fit.',
        'notify_later' => 'You chose to decide on notification timing later.',
        'notify_rest' => 'Choosing an activity, buying a report or taking a resource subscription does not turn notifications on. General resource notifications require a separate opt-in.',
        'blocks2' => [
            'Return when it suits you',
            'Keep your report and Tough-Day Guide as references. There is no requirement to work through the report on a schedule, and buying this report does not enrol you in a personalised journey or resource subscription.',
        ],
    ],
];
