#!/usr/bin/env python3
"""Exact, offline candidate-policy simulation. Never writes chain state or genesis."""
import argparse
import json
import re
from decimal import Decimal, localcontext
from fractions import Fraction
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / 'docs/economics/CANDIDATE_POLICY.json'
YEAR_SECONDS = 31_557_600  # 365.25 days; explicit simulation convention
MAX_AMOUNT = 2**63 - 1


def decimal(value):
    if not isinstance(value, str) or not re.fullmatch(r'[0-9]+(?:\.[0-9]+)?', value):
        raise ValueError('Use an unsigned plain decimal string')
    return Fraction(value)


def units(value, scale=1_000_000):
    result = decimal(value) * scale
    if result.denominator != 1 or result > MAX_AMOUNT:
        raise ValueError('Amount is not exactly representable in supported base units')
    return result.numerator


def ceil(value):
    return -(-value.numerator // value.denominator)


def display(value, places=6):
    with localcontext() as context:
        context.prec = 60
        number = Decimal(value.numerator) / Decimal(value.denominator)
        return format(number, f'.{places}f')


def quote_gru(gru, usd_per_gwd, subsidy_percent=0):
    """Illustrative quote only: total ceil, floor the subsidy share of that total, user pays remainder."""
    if not isinstance(subsidy_percent, int) or isinstance(subsidy_percent, bool) or not 0 <= subsidy_percent <= 100:
        raise ValueError('Invalid subsidy percentage')
    cost = decimal(gru) * Fraction(1, 100)
    price = decimal(usd_per_gwd)
    if price <= 0:
        raise ValueError('A positive reference price is required')
    exact_base = cost * 1_000_000 / price
    total = ceil(exact_base)
    subsidy_exact = Fraction(total) * subsidy_percent / 100
    subsidy = subsidy_exact.numerator // subsidy_exact.denominator
    if total > MAX_AMOUNT:
        raise ValueError('Settlement exceeds supported amount range')
    return {'gru': gru, 'hypothetical_usd_per_gwd': usd_per_gwd,
            'provider_base_units': str(total), 'subsidy_base_units': str(subsidy),
            'user_base_units': str(total - subsidy), 'subsidy_percent': subsidy_percent}


def runway(reserve, rewards, releases_per_epoch_year, cadence):
    remaining, height = reserve, 0
    for reward in rewards[:4]:
        count = 4 * releases_per_epoch_year
        capacity = count * reward
        if remaining <= capacity:
            height += ceil(Fraction(remaining, reward))
            break
        remaining -= capacity
        height += count
    else:
        height += ceil(Fraction(remaining, rewards[4]))
    demand = 4 * releases_per_epoch_year * sum(rewards[:4])
    return {'block_seconds_assumption': cadence, 'releases_per_epoch_year': releases_per_epoch_year,
            'first_four_epochs_scheduled_demand_base_units': str(demand),
            'remaining_after_first_four_epochs_base_units': str(max(0, reserve - demand)),
            'exhaustion_block': height,
            'exhaustion_calendar_years_assuming_continuous_cadence': display(Fraction(height * cadence, YEAR_SECONDS)),
            'first_four_epochs_calendar_years': display(Fraction(16 * releases_per_epoch_year * cadence, YEAR_SECONDS))}


def validate(policy):
    if policy.get('production_authorized') is not False:
        raise ValueError('This simulator accepts only explicitly non-production policies')
    asset = policy['asset']
    if asset['candidate_decimals'] != 6 or asset['post_genesis_minting'] is not False:
        raise ValueError('Unsupported candidate asset model')
    total = units(asset['genesis_gwd'])
    if total != 1_000_000_000_000_000 or str(total) != asset['genesis_base_units']:
        raise ValueError('Incorrect candidate issuance')
    allocation = 0
    for reserve in policy['reserves'].values():
        amount = units(reserve['allocation_gwd'])
        if str(amount) != reserve['allocation_base_units']:
            raise ValueError('Reserve unit mismatch')
        allocation += amount
    if allocation != total:
        raise ValueError('Allocations do not equal genesis issuance')
    if policy['fees']['burn_percent'] != 0 or policy['fees']['operator_percent'] != 100:
        raise ValueError('Candidate must honor the user-selected no-burn policy')
    if policy['gru']['transferable'] is not False or policy['gru']['part_of_gwd_supply'] is not False:
        raise ValueError('GRU must remain nontransferable accounting')


def simulate(policy):
    validate(policy)
    rewards = [units(v) for v in policy['emissions']['rewards_gwd']]
    if len(rewards) != 5 or any(v <= 0 for v in rewards):
        raise ValueError('Expected five positive simulation rewards')
    reserve = units(policy['reserves']['security_emissions']['allocation_gwd'])
    fixed = policy['emissions']['current_declared_releases_per_year']
    calibrated, unchanged = [], []
    for cadence in (60, 10, 6):
        calibrated.append(runway(reserve, rewards, YEAR_SECONDS // cadence, cadence))
        unchanged.append(runway(reserve, rewards, fixed, cadence))
    caps = {key: str(Fraction(decimal(row['allocation_gwd']), decimal(row['release_ceiling_per_window_gwd'])))
            for key, row in policy['reserves'].items() if row['release_ceiling_per_window_gwd'] is not None}
    return {'status': 'offline_scenarios_not_genesis_authorization', 'year_seconds': YEAR_SECONDS,
            'confirmed_target_block_seconds': 60,
            'confirmed_60_second_baseline': calibrated[0],
            'counterfactual_cadence_sensitivity_not_policy_options': calibrated,
            'unchanged_current_height_epochs_at_different_actual_cadences': unchanged,
            'allocation_divided_by_release_ceiling_window_equivalents': caps,
            'gwd_quote_examples': [quote_gru('1000', p) for p in ('0.005', '0.01', '0.05', '0.10', '1.00')],
            'subsidy_example': quote_gru('5000', '0.01', 80),
            'warnings': ['Prices and resource rates are hypothetical, not market values or provider-cost validation.',
                         'Quote examples have no signatures, verification proof or spending authorization.',
                         'The target block interval is fixed at 60 seconds; reward eligibility/distribution and idle-chain clock semantics remain unresolved.',
                         'No live chain configuration, balances, quotes, keys or genesis files are written.']}


def report(result):
    lines = ['# Candidate economics simulation', '',
             'Offline arithmetic only; no production authorization. Years use 365.25 days.', '',
             '## Confirmed policy: 60-second target blocks', '',
             '| Assumed block interval | First 16 nominal years: scheduled GWD | Reserve exhaustion (calendar years) |',
             '|---|---:|---:|']
    for row in [result['confirmed_60_second_baseline']]:
        amount = display(Fraction(int(row['first_four_epochs_scheduled_demand_base_units']), 1_000_000))
        lines.append(f"| {row['block_seconds_assumption']} seconds | {amount} | {row['exhaustion_calendar_years_assuming_continuous_cadence']} |")
    lines += ['', 'At the established 60-second target, the first sixteen nominal years release 52,596,000 GWD, leaving 97,404,000 GWD under the selected 15/25/30/25/5 allocation. The table reports the theoretical reserve runway; this is token arithmetic, not proof of sustainable operating costs.', '',
              '## Counterfactual sensitivity only — not proposed block-interval changes', '',
              '| Actual continuous block interval | First four epochs (calendar years) | Reserve exhaustion (calendar years) |',
              '|---|---:|---:|']
    for row in result['unchanged_current_height_epochs_at_different_actual_cadences']:
        lines.append(f"| {row['block_seconds_assumption']} seconds | {row['first_four_epochs_calendar_years']} | {row['exhaustion_calendar_years_assuming_continuous_cadence']} |")
    lines += ['', 'The 10- and 6-second rows below are counterfactual diagnostics, not unresolved policy choices. These rows use the same fixed block epochs. The first four epochs release 52,596,000 GWD in every row; faster blocks change the elapsed calendar time, not this amount.', '',
              '## GRU settlement examples', '', '| Service GRU | Hypothetical USD/GWD | GWD required |', '|---:|---:|---:|']
    for row in result['gwd_quote_examples']:
        lines.append(f"| {row['gru']} | {row['hypothetical_usd_per_gwd']} | {display(Fraction(int(row['provider_base_units']),1_000_000))} |")
    lines += ['', 'The 5,000-GRU example at $0.01/GWD with an 80% subsidy pays 5,000 GWD to the provider: 4,000 from subsidy funding and 1,000 from the user. Network transaction fees are separate and are not burned.', '',
              '## Budget window equivalents', '']
    for key, value in result['allocation_divided_by_release_ceiling_window_equivalents'].items():
        lines.append(f'- {key}: {value} full-cap windows. These are ceilings, not expected expenditure or validated operational runway.')
    lines += ['', 'No USD purchasing power, market price, revenue forecast or sustainable provider margin is established by these calculations.', '']
    return '\n'.join(lines)


def self_test():
    assert units('1000000000') == 1_000_000_000_000_000
    for value in ('0.0000001', '-1', 'NaN', '1e6', '9223372036854775808'):
        try:
            units(value)
        except ValueError:
            pass
        else:
            raise AssertionError(value)
    for price in ('0', '-1', 'NaN'):
        try:
            quote_gru('1', price)
        except ValueError:
            pass
        else:
            raise AssertionError(price)
    q = quote_gru('5000', '0.01', 80)
    assert q['provider_base_units'] == '5000000000'
    assert q['subsidy_base_units'] == '4000000000'
    assert q['user_base_units'] == '1000000000'
    dust = quote_gru('0.000001', '1', 80)
    assert quote_gru('0.000001', '1', 100)['user_base_units'] == '0'
    assert int(dust['user_base_units']) + int(dust['subsidy_base_units']) == int(dust['provider_base_units'])
    r = [units(v) for v in ('10', '7.5', '5', '2.5', '1')]
    fixed = [runway(units('400000000'), r, 525960, v) for v in (60, 10, 6)]
    assert len({v['exhaustion_block'] for v in fixed}) == 1
    assert fixed[0]['first_four_epochs_scheduled_demand_base_units'] == '52596000000000'
    assert runway(units('1'), r, 525960, 60)['exhaustion_block'] == 1
    bad = json.loads(POLICY.read_text())
    bad['reserves']['grants']['allocation_gwd'] = '1'
    try:
        validate(bad)
    except ValueError:
        pass
    else:
        raise AssertionError('Bad allocations accepted')
    print('PASS: exact units, no-burn policy, supply sum, quote conservation, malformed inputs, and emission cadence semantics')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--self-test', action='store_true')
    parser.add_argument('--write-report', action='store_true', help='Write only candidate simulation JSON and Markdown under docs/economics')
    args = parser.parse_args()
    if args.self_test:
        self_test()
    policy = json.loads(POLICY.read_text())
    result = simulate(policy)
    if args.write_report:
        (ROOT / 'docs/economics/SIMULATION_RESULTS.json').write_text(json.dumps(result, indent=2) + '\n')
        (ROOT / 'docs/economics/SIMULATION_RESULTS.md').write_text(report(result))
    elif not args.self_test:
        print(json.dumps(result, indent=2))
