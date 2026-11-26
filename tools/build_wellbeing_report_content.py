"""Build lib/wellbeing_report_content.php from Nic's master report document.

Usage (from the repo root):
    python -I tools/build_wellbeing_report_content.py \
        "References/My_Imprint_June_Approved_Choices_Update/My_Imprint_Consolidated_Master_for_June_v4_Primary_and_Secondary_Choices_2026-11-02.docx"

Source: "Appendix C Complete twelve section paid report" (participant copy) and its
"Appendix B Restricted personal support wording" in the consolidated master v4
(2 Nov 2026). Participant wording is copied exactly; implementation notes in the
document are not copied. Re-run this script when Nic sends a new master, then review
the diff of the generated PHP file.

The output is a PHP file (not JSON) on purpose: lib/ is inside the web root, and a PHP
file is executed rather than served, so the paid report text is never downloadable.
"""

from __future__ import annotations

import re
import sys
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
SOURCE_VERSION = 'master-v4-2026-11-02'

CODES = {
    'Teenager Hurting': 'TH', 'Teenager Fired Up': 'TF',
    'Bodyguard Shutting Down': 'BS', 'Bodyguard Pushing Through': 'BP',
    'Adult Stretched': 'AS', 'Adult Steady': 'AD', 'Adult': 'A',
}
COPING = {
    'Push through and keep busy': 'a', 'Withdraw and go quiet': 'b', 'Distract yourself': 'c',
    'Get hard on yourself': 'd', 'Reach out to someone': 'e', 'Try to numb it out': 'f',
    'Overthink and analyse': 'g', 'Get short or snappy with people': 'h',
    'Act first, think later': 'i', 'Slow down and work out what I need': 'j',
    'Look for comfort or reassurance': 'k', 'Put pressure on myself to get everything right': 'l',
}
LIFE_AREAS = {
    'Work or study': 'a', 'Money': 'b', 'Relationships or family': 'c', 'Health': 'd',
    'Loss or grief': 'e', 'Loneliness or feeling cut off from people': 'f',
    'Becoming a parent or caring for someone who is unwell': 'g',
    'Moving house or city or another big change': 'h', 'An accident or a frightening event': 'i',
    'Alcohol or other substances': 'j',
}
SUPPORT = {
    'Having someone just listen': 'a', 'Being given practical steps': 'b',
    'Being left to work it out alone': 'c', 'Gentle encouragement': 'd',
    'Being checked in on': 'e', 'Honest direct feedback': 'f', 'Not being a burden to anyone': 'g',
}
ACTIVITIES = {
    'Time alone': 'a', 'Trusted person': 'b', 'Movement': 'c', 'Outdoors': 'd',
    'Listening to music': 'e', 'Writing': 'f', 'Quiet': 'g', 'Creative': 'h', 'Animal': 'i',
    'Practical': 'j', 'Humour': 'k', 'Rest': 'l', 'Family': 'm',
}
STATEMENTS = ['3.1', '3.2', '3.3', '3.4', '3.5']


# ─────────────────────────────────────────────────────────────────────────────
# Reading the document into a heading tree
# ─────────────────────────────────────────────────────────────────────────────

def read_paragraphs(docx: Path) -> list[tuple[str, str]]:
    root = ET.fromstring(zipfile.ZipFile(docx).read('word/document.xml'))
    out = []
    for el in root.find(W + 'body'):
        if el.tag != W + 'p':
            continue  # tables in Appendix C are status overviews, not participant copy
        text = ''.join(t.text or '' for t in el.iter(W + 't')).strip()
        if not text:
            continue
        style = el.find(W + 'pPr/' + W + 'pStyle')
        out.append((style.get(W + 'val') if style is not None else '', text))
    return out


class Node:
    def __init__(self, level: int, title: str):
        self.level, self.title, self.paras, self.children = level, title, [], []

    def child(self, title: str) -> 'Node':
        found = [c for c in self.children if c.title == title]
        if len(found) != 1:
            raise SystemExit(f'Expected one heading "{title}" under "{self.title}", found {len(found)}')
        return found[0]


def build_tree(paras: list[tuple[str, str]], start: str, end: str) -> Node:
    """Heading tree for the paragraphs between Heading1 `start` (inclusive) and `end` (exclusive)."""
    i = next(k for k, (s, t) in enumerate(paras) if s == 'Heading1' and t == start)
    j = next(k for k, (s, t) in enumerate(paras) if k > i and s == 'Heading1' and t == end)
    root = Node(0, 'root')
    stack = [root]
    for style, text in paras[i:j]:
        m = re.fullmatch(r'Heading([123])', style)
        if m:
            level = int(m.group(1))
            while stack[-1].level >= level:
                stack.pop()
            node = Node(level, text)
            stack[-1].children.append(node)
            stack.append(node)
        else:
            stack[-1].paras.append(text)
    return root


def section(tree: Node, number: int) -> Node:
    found = [c for c in tree.children if c.level == 1 and c.title.startswith(f'{number} ')]
    if len(found) != 1:
        raise SystemExit(f'Section {number} not found exactly once')
    return found[0]


def unquote(text: str, prefix: str) -> str:
    """'Approved participant copy: “...”' -> '...'"""
    if not text.startswith(prefix):
        raise SystemExit(f'Expected "{prefix}" at: {text[:80]}')
    body = text[len(prefix):].strip()
    if not (body.startswith('“') and body.endswith('”')):
        raise SystemExit(f'Expected curly quotes in: {text[:80]}')
    return body[1:-1]


def expect(paras: list[str], count: int, where: str) -> list[str]:
    if len(paras) != count:
        raise SystemExit(f'{where}: expected {count} paragraph(s), found {len(paras)}: {paras!r}')
    return paras


def items_with_lists(paras: list[str]) -> list:
    """Section 3 strength / early-sign blocks: lower-case lines are bullet points."""
    out: list = []
    for p in paras:
        if p[0].islower():
            if out and isinstance(out[-1], dict):
                out[-1]['list'].append(p)
            else:
                out.append({'list': [p]})
        else:
            out.append(p)
    return out


# ─────────────────────────────────────────────────────────────────────────────
# Sections
# ─────────────────────────────────────────────────────────────────────────────

def build(tree: Node, appendix_b: Node) -> dict:
    c: dict = {'source': SOURCE_VERSION}

    titles = {}
    for n in range(1, 13):
        titles[n] = re.sub(r'^\d+ ', '', section(tree, n).title)
    titles[9] = 'Your 90-day journey'  # heading in the document reads "ninety day"
    c['titles'] = titles

    # 1 How to read this report (first paragraph of the node is an implementation note)
    s1 = section(tree, 1)
    c['s1'] = {
        'opening': expect(s1.paras[1:], 1, 's1 opening'),
        'characters': expect(s1.child('When character language is welcome').paras, 1, 's1 characters'),
        'plain': expect(s1.child('When plain language is preferred').paras[:1], 1, 's1 plain'),
        'support': expect(s1.child('When plain language is preferred').paras[1:], 1, 's1 support'),
    }

    # 2 Meet your crew
    s2 = section(tree, 2)
    c['s2'] = {
        'characters': expect(s2.child('Character language accepted').paras, 2, 's2 characters'),
        'plain': expect(s2.child('Plain language preferred').paras, 1, 's2 plain'),
    }

    # 3 When things get tough
    s3 = section(tree, 3)
    primaries = {}
    for name in ['Teenager Hurting', 'Teenager Fired Up', 'Bodyguard Shutting Down',
                 'Bodyguard Pushing Through', 'Adult Stretched', 'Adult Steady']:
        node = s3.child(name)
        primaries[CODES[name]] = {
            'intro': expect(node.paras, 1, f's3 {name} intro'),
            'blocks': [{'heading': b.title, 'items': items_with_lists(b.paras)} for b in node.children],
        }
    plain = s3.child('Plain language or unresolved result').paras
    c['s3'] = {
        'primary': primaries,
        'plain': {
            'template': plain[1].replace('[saved question 4.1 responses in natural language]', '{q41}'),
            'follow': plain[2],
        },
    }
    assert '{q41}' in c['s3']['plain']['template']

    # 4 When it gets really hard
    s4 = section(tree, 4)
    journey_note = 'You can use your report and 90-day wellbeing journey to notice what fits and explore manageable responses. Your reminder choices remain separate: you choose whether reminders are on, and when and how often they arrive.'
    secondaries = {}
    for name in ['Teenager Hurting', 'Teenager Fired Up', 'Bodyguard Shutting Down', 'Bodyguard Pushing Through', 'Adult']:
        node = s4.child(name)
        blocks = []
        for b in node.children:
            paras = list(b.paras)
            if paras and paras[-1] == journey_note:
                paras = paras[:-1]  # refers to the 90-day journey, which this purchase does not include
            blocks.append({'heading': b.title, 'items': paras})
        secondaries[CODES[name]] = {'intro': expect(node.paras, 1, f's4 {name} intro'), 'blocks': blocks}
    none = s4.child('When there is no confirmed distinct secondary')
    pairs = {}
    for group in s4.children:
        m = re.fullmatch(r'(.+) primary', group.title)
        if not m:
            continue
        primary = CODES[m.group(1)]
        for pair in group.children:
            first, second = pair.title.split(' and ')
            assert CODES[first] == primary, pair.title
            secondary = CODES[second]
            pairs[f'{primary}_{secondary}'] = expect(pair.paras, 1, f'pair {pair.title}')[0]
    if len(pairs) != 24:
        raise SystemExit(f'Expected 24 pairings, found {len(pairs)}')
    plain42 = unquote(s4.child('Plain-language account of the really-hard response').paras[0], 'Proposed counterpart:')
    c['s4'] = {
        'secondary': secondaries,
        'transition': {
            'none': unquote(none.child('No distinct secondary supported').paras[0], 'Approved participant copy:'),
            'pending': unquote(none.child('Weak candidate awaiting an answer').paras[0], 'Approved participant copy:'),
            'declined': unquote(none.child('Weak candidate declined uncertain or skipped').paras[0], 'Approved participant copy:'),
        },
        'plain': plain42.replace('[saved question 4.2 response in natural language]', '{q42}'),
        'pairs_heading': 'How these responses may fit together',
        'pairs': pairs,
    }
    assert '{q42}' in c['s4']['plain']

    # 5 What is underneath
    s5 = section(tree, 5)
    statements = {}
    for qid, node in zip(STATEMENTS, [n for n in s5.children if re.match(r'\d\. ', n.title)]):
        stmt = re.fullmatch(r'Statement: "(.+)"', node.paras[0])
        high = node.child('Higher response block for ratings 3 and 4').paras
        mid = node.child('Middle response block for rating 2').paras
        low = node.child('Lower response block for ratings 0 and 1').paras
        fragment = re.fullmatch(r'Not like me\. Fragment: (.+)', low[-1])
        if not stmt or not fragment:
            raise SystemExit(f's5 {node.title}: unexpected statement or fragment')
        statements[qid] = {
            'title': re.sub(r'^\d\. ', '', node.title),
            'statement': stmt.group(1),
            'high': {'title': high[0], 'paras': high[1:]},
            'mid': {'title': mid[0], 'paras': mid[1:]},
            'fragment': fragment.group(1),
        }
    c['s5'] = {
        'opening': s5.paras[1],
        'high_sentence': 'The statements you rated quite like you or very like you were {list}.',
        'statements': statements,
        'less_heading': 'What is less like you',
        'zeros': 'You rated the following as not like you: {list}.',
        'ones': 'You rated the following as a little like you: {list}.',
        'labels': {0: 'not like me', 1: 'a little like me', 2: 'somewhat like me', 3: 'quite like me', 4: 'very like me'},
    }
    # The two group sentences and the high sentence are quoted inside instructions in the document.
    less = s5.child('What is less like you').paras[0]
    assert '“You rated the following as not like you: [matching source fragments].”' in less
    assert '“You rated the following as a little like you: [matching source fragments].”' in less
    assert '“The statements you rated quite like you or very like you were [matching statement titles].”' in s5.paras[2]

    # 6 How you cope
    s6 = section(tree, 6)
    opening = s6.child('Opening').paras
    helpful = s6.child('Overall helpfulness response')
    coping = {}
    for title, letter in COPING.items():
        coping[f'4.1.{letter}'] = expect(s6.child(title).paras, 1, f's6 {title}')[0]
    closings = s6.child('Closing options for the accepted primary')
    c['s6'] = {
        'opening': opening[0]
            .replace('[selected 4.1 responses in natural language]', '{q41}')
            .replace('[exact saved 4.3 label or value]', '{q43}'),
        'context42': unquote(opening[1].split(' Keep that context')[0], 'Proposed additional context sentence, only with a saved question 4.2 answer:').replace('[4.2 response]', '{q42}'),
        'helpful': {
            'low': helpful.child('Overall 4.3 rating 0 or 1').paras[0],
            'mid': helpful.child('Overall 4.3 rating 2').paras[0],
            'high': helpful.child('Overall 4.3 rating 3 or 4').paras[0],
        },
        'coping': coping,
        'closing': {
            **{CODES[n]: closings.child(n).paras[0] for n in ['Teenager Hurting', 'Teenager Fired Up', 'Bodyguard Shutting Down', 'Bodyguard Pushing Through', 'Adult Stretched', 'Adult Steady']},
            'neutral': closings.child('Character framing rejected or result unresolved').paras[0],
        },
    }
    assert '{q41}' in c['s6']['opening'] and '{q43}' in c['s6']['opening'] and '{q42}' in c['s6']['context42']

    # 7 What is on your plate
    s7 = section(tree, 7)
    op = s7.child('Opening and current impact')
    first, second = op.paras[0].split('. ', 1)
    c['s7'] = {
        'opening': (first + '.').replace('[selected question 1.4 areas] [is/are]', '{areas} {isare}'),
        'impact': second.replace('[saved question 1.2 rating]', '{n}'),
        'recent': op.child('Recent change only when question 1.5 is Yes').paras[0],
        'impact_high': op.child('Impact rated 8 to 10').paras[0],
        'impact_mid': op.child('Impact rated 4 to 7').paras[0],
        'impact_low': op.child('Impact rated 0 to 3').paras[0],
        'areas': {f'1.4.{letter}': {'heading': title, 'text': expect(s7.child(title).paras, 1, f's7 {title}')[0]}
                  for title, letter in LIFE_AREAS.items()},
    }
    assert '{areas}' in c['s7']['opening'] and '{n}' in c['s7']['impact']

    # 8 What helps you
    s8 = section(tree, 8)
    eligible = s8.child('Opening when personal support suggestions are eligible')
    recharge = s8.child('How you recharge')
    settle = s8.child('How you settle')
    c['s8'] = {
        'opening': eligible.paras[0].replace('[saved question 5.1 preferences]', '{prefs}'),
        'prefs': {f'5.1.{letter}': {'heading': title, 'text': eligible.child(title).paras[0]} for title, letter in SUPPORT.items()},
        'unavailable': s8.child('When personal support is unavailable').paras[0],
        'unsafe': unquote(s8.child('When home or a relationship feels unsafe').paras[0], 'Proposed participant wording:'),
        'recharge_heading': 'How you recharge',
        'recharge': {
            'alone': recharge.child('Time on my own').paras[0],
            'people': recharge.child('Time with people').paras[0],
            'mix': recharge.child('A mix of both').paras[0],
            'depends': recharge.child('It depends on the day').paras[0],
        },
        'settle_heading': 'How you settle',
        'settle': {
            'movement': settle.child('Moving my body').paras[0],
            'still': settle.child('Being still and quiet').paras[0],
            'hands': settle.child('Doing something with my hands').paras[0],
            'talking': settle.child('Talking it through').paras[0],
            'none': s8.child('No supported settling preference').paras[0],
        },
    }
    assert '{prefs}' in c['s8']['opening']

    # 9 Your 90-day journey: kept for when the journey is sold; not shown with the report-only purchase.
    s9 = section(tree, 9)
    c['s9'] = {'paras': s9.paras[1:]}

    # 10 Your Tough Day Guide
    s10 = section(tree, 10)
    step1 = s10.child('Step 1 Notice what is happening')
    step2 = s10.child('Step 2 Try one familiar small thing')
    closing = s10.child('Optional closing emphasis by confirmed secondary')
    urgent = s10.child('Australian urgent and specialist support').paras
    c['s10'] = {
        'opening': expect(s10.paras[1:], 1, 's10 opening'),
        'step1_heading': 'Step 1: Notice what is happening',
        'step1': {
            **{CODES[n]: step1.child(n).paras[0] for n in ['Teenager Hurting', 'Teenager Fired Up', 'Bodyguard Shutting Down', 'Bodyguard Pushing Through', 'Adult']},
            'neutral': step1.child('Neutral version').paras[0],
        },
        'pressure': s10.child('Explicit pressure-to-get-things-right response only').paras[0],
        'step2_heading': 'Step 2: Try one familiar small thing',
        'step2': {f'6.5.{letter}': step2.child(title).paras[0] for title, letter in ACTIVITIES.items()},
        'step2_none': s10.child('Something else unsure no nominated activity or missing answer').paras[0],
        'step2_contact_excluded': s10.child('Personal-contact activity unavailable or excluded').paras[0],
        'step3_heading': 'Step 3: Know where to turn',
        'step3': s10.child('Step 3 Know where to turn').paras[0],
        'closing': {
            **{CODES[n]: closing.child(n).paras[0] for n in ['Teenager Hurting', 'Teenager Fired Up', 'Bodyguard Shutting Down', 'Bodyguard Pushing Through', 'Adult']},
            'neutral': closing.child('When no secondary is confirmed or character framing is rejected').paras[0],
        },
        'urgent_heading': 'Australian urgent and specialist support',
        'urgent': [urgent[0], urgent[3]],
        'urgent_links': [
            {'label': 'Emergency: call 000', 'href': 'tel:000'},
            {'label': 'Lifeline: 13 11 14', 'href': 'tel:131114'},
            {'label': '1800RESPECT: 1800 737 732', 'href': 'tel:1800737732'},
            {'label': '1800RESPECT contact options', 'href': 'https://www.1800respect.org.au/'},
        ],
    }
    assert urgent[1] == 'Lifeline phone: 13 11 14' and urgent[2] == '1800RESPECT contact options', urgent

    # 11 Looking ahead: depends on the journey and membership; not shown with the report-only purchase.
    s11 = section(tree, 11)
    c['s11'] = {'paras': s11.child('Initial report preview').paras}

    # 12 About this report
    s12 = section(tree, 12)
    c['s12'] = {'paras': expect(s12.paras[1:], 4, 's12')}

    # Appendix B: proposed variants when personal support is unavailable (also used when unsafe)
    b = appendix_b
    c['restricted'] = {
        's4_help_TH': b.child('Section 4 Teenager Hurting helpful-response paragraph').paras[0],
        's4_help_TF': b.child('Section 4 Teenager Fired Up helpful-response paragraph').paras[0],
        's4_help_BP': b.child('Section 4 Bodyguard Pushing Through helpful-response paragraph').paras[0],
        's4_happens_A': b.child('Section 4 Adult What happens paragraph').paras[0],
        'pair_TF_A_repair': b.child('Pairing Teenager Fired Up primary Adult secondary final repair sentence').paras[0],
        's6_4.1.b': b.child('Section 6 Withdraw and go quiet').paras[0],
        's6_4.1.e': b.child('Section 6 Reach out to someone').paras[0],
        's6_4.1.h': b.child('Section 6 Get short or snappy with people').paras[0],
        's6_4.1.k': b.child('Section 6 Look for comfort or reassurance').paras[0],
        's10_step3': b.child('Section 10 Step 3 with unavailable support').paras[0],
    }
    tf_a = c['s4']['pairs']['TF_A']
    assert tf_a.endswith('If you decide that a repair is needed, choose a manageable and safe way to make it; direct contact with someone unsafe or unavailable is not required.')
    return c


# ─────────────────────────────────────────────────────────────────────────────
# PHP output
# ─────────────────────────────────────────────────────────────────────────────

def php(value, indent: int = 0) -> str:
    pad = '    ' * indent
    if isinstance(value, dict):
        if not value:
            return '[]'
        lines = [f"{pad}    {php(k)} => {php(v, indent + 1).lstrip()}," for k, v in value.items()]
        return '[\n' + '\n'.join(lines) + f'\n{pad}]'
    if isinstance(value, list):
        if not value:
            return '[]'
        lines = [f"{pad}    {php(v, indent + 1).lstrip()}," for v in value]
        return '[\n' + '\n'.join(lines) + f'\n{pad}]'
    if isinstance(value, bool):
        return 'true' if value else 'false'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, str):
        return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    raise TypeError(type(value))


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit(__doc__)
    paras = read_paragraphs(Path(sys.argv[1]))
    report = build_tree(paras, '1 How to read this report', 'Appendix A Assembly rules for June')
    appendix_b = build_tree(paras, 'Appendix B Restricted personal support wording', 'Appendix C Revision record and remaining decisions')
    content = build(report, appendix_b.children[0])
    out = Path(__file__).resolve().parent.parent / 'lib' / 'wellbeing_report_content.php'
    out.write_text(
        '<?php\n'
        '/**\n'
        ' * Tools for the Tough Days — Wellbeing Profile full report: participant copy\n'
        ' *\n'
        ' * GENERATED by tools/build_wellbeing_report_content.py from Nic\'s consolidated master v4\n'
        ' * (2 Nov 2026), Appendix C and its Appendix B. Do not edit by hand: re-run the script.\n'
        ' * Assembly rules live in lib/wellbeing_report.php.\n'
        ' */\n\n'
        'declare(strict_types=1);\n\n'
        'return ' + php(content) + ';\n',
        encoding='utf-8', newline='\n')
    print(f'Wrote {out}')


if __name__ == '__main__':
    main()
