#!/usr/bin/env python3
"""Five exact comparative scenarios. Illustrative inputs; never certifies affordability or writes genesis."""
import importlib.util
import json
from fractions import Fraction
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('economics', ROOT / 'scripts/simulate-institute-economics.py')
e = importlib.util.module_from_spec(spec)
spec.loader.exec_module(e)
DESIGNS = {
    'original_40_20_15_24_1': {'allocations': [400000000, 200000000, 150000000, 240000000, 10000000], 'caps': [1000000, 750000, 1200000, 100000]},
    'proposed_10_25_30_34_1': {'allocations': [100000000, 250000000, 300000000, 340000000, 10000000], 'caps': [250000, 300000, 340000, 100000]},
}


def compare():
    year_rewards = 525960 * 10
    rewards = [e.units(v) for v in ('10', '7.5', '5', '2.5', '1')]
    results = {}
    for name, design in DESIGNS.items():
        allocations, caps = design['allocations'], design['caps']
        assert sum(allocations) == 1000000000
        runway = e.runway(allocations[0] * 1000000, rewards, 525960, 60)
        rates = []
        for gwd_per_gru in (1, 5, 10):
            provider = 100000 * gwd_per_gru
            subsidy = provider * 80 // 100
            rates.append({'developers': 100, 'gru_per_developer': 1000, 'total_gru': 100000,
                          'provider_accepted_gwd_per_gru': gwd_per_gru, 'provider_gwd': provider,
                          'customer_gwd': provider - subsidy, 'subsidy_gwd': subsidy,
                          'within_gwd_cap': subsidy <= caps[1], 'within_pilot_gru_cap': True,
                          'protocol_payout_enabled': False})
        results[name] = {
            'allocations_gwd': allocations,
            'rolling_window_release_caps_gwd': caps,
            'total_discretionary_ceiling_per_window_gwd': sum(caps),
            'security_runway': runway,
            'zero_commercial_adoption': {'customer_revenue_assumed': '0', 'fee_transactions_per_day': 0,
                'annual_network_rewards_gwd_at_continuous_target': year_rewards, 'operating_cost_budget_usd': None,
                'affordability_established': False, 'reason': 'Actual obligations, cash resources and realized GWD purchasing power are not supplied.'},
            'low_purchasing_power': [{'hypothetical_usd_per_gwd': price,
                'gwd_per_validator_per_month': e.display(Fraction(year_rewards, 3 * 12)),
                'hypothetical_usd_per_validator_per_month': e.display(Fraction(year_rewards, 3 * 12) * Fraction(price), 2),
                'market_liquidity_or_realizable_value_established': False} for price in ('0.001', '0.005', '0.01', '0.05')],
            'rapid_application_growth': {'pilot_gru_ceiling': 100000, 'price_sensitivity': rates,
                'expanded_demand': {'developers': 1000, 'gru_per_developer': 1000, 'requested_gru': 1000000,
                    'within_pilot_gru_cap': False, 'protocol_payout_enabled': False},
                'capacity_escrow_and_usage_verification_established': False},
            'validator_expansion': [{'hypothetical_validator_count': count,
                'annual_gwd_per_validator': e.display(Fraction(year_rewards, count)),
                'membership_change_implemented': False} for count in (3, 4, 10, 25)],
            'treasury_stress': {'signer_loss': [{'lost_keys': lost, 'available_keys': 5-lost,
                'ordinary_threshold_available': 5-lost >= 3, 'rotation_threshold_available': 5-lost >= 4}
                for lost in (1, 2, 3)], 'lost_quorum_recovery_implemented': False,
                'guard_pause_spending_authority': False, 'related_beneficiary_group_registry_required': True},
        }
    results['selected_15_25_30_25_5'] = {
        'allocations_gwd': [150000000, 250000000, 300000000, 250000000, 50000000],
        'security_runway': e.runway(150000000 * 1000000, rewards, 525960, 60),
        'rolling_window_release_caps_gwd': None,
        'formal_ratification_required': True,
        'spending_authority': False,
        'recovery_policy_authorized': False,
    }
    return {'status': 'selected_allocation_pending_formal_ratification',
        'fixed_assumptions': {'block_seconds': 60, 'nominal_year_seconds': 31557600, 'blocks_per_nominal_year': 525960,
            'continuous_finalization_assumed_for_reward_math': True, 'gwd_decimals': 6, 'inflation': False, 'burn': False, 'fees_to_operators_percent': 100,
            'service_demand_prices_and_cost_inputs_identical_between_designs': True,
            'pilot': {'developers': 100, 'gru_per_developer_per_window': 1000, 'subsidy_percent': 80},
            'prices_are_hypothetical_not_forecasts': True,
            'external_provider_price_claims_not_validated': True}, 'designs': results,
        'governance_resolution': '15/25/30/25/5 selected for fresh production; formal ratification, budgets and recovery remain unresolved.'}


def check(result):
    old, new = (result['designs'][name] for name in ('original_40_20_15_24_1', 'proposed_10_25_30_34_1'))
    assert old['security_runway']['first_four_epochs_scheduled_demand_base_units'] == new['security_runway']['first_four_epochs_scheduled_demand_base_units'] == '52596000000000'
    assert new['total_discretionary_ceiling_per_window_gwd'] == 990000
    assert old['total_discretionary_ceiling_per_window_gwd'] == 3050000
    assert new['security_runway']['remaining_after_first_four_epochs_base_units'] == '47404000000000'
    assert old['rapid_application_growth']['price_sensitivity'][1]['within_gwd_cap'] is True
    assert new['rapid_application_growth']['price_sensitivity'][1]['within_gwd_cap'] is False
    assert all(not d['zero_commercial_adoption']['affordability_established'] for d in (old, new))
    assert all(d['rapid_application_growth']['expanded_demand']['within_pilot_gru_cap'] is False for d in (old, new))
    selected = result['designs']['selected_15_25_30_25_5']
    assert sum(selected['allocations_gwd']) == 1000000000
    assert selected['security_runway']['remaining_after_first_four_epochs_base_units'] == '97404000000000'
    assert selected['rolling_window_release_caps_gwd'] is None
    assert new['treasury_stress']['signer_loss'][1]['rotation_threshold_available'] is False


def markdown(result):
    selected = result['designs']['selected_15_25_30_25_5']
    return f"""# Selected production allocation — November 14, 2026

15/25/30/25/5 is selected for a fresh 1-billion-GWD genesis: security 150 million,
grants 250 million, subsidies 300 million, Institute treasury 250 million and
operations 50 million. Six-decimal precision, no later minting and no burning remain.
The earlier two designs remain explicit historical comparisons in the JSON model.

Using the protocol's 525,960-block nominal year, the first sixteen nominal years
consume 52,596,000 GWD, leaving 97,404,000 GWD in security if no other withdrawals
occur. This uses 365.25-day years, so it differs from the proposal's rounded
52.56-million figure. Continued rewards require finalized blocks and available
reserve balances; no affordability or liquid market value is inferred.

The selected reserve exhausts after
{selected['security_runway']['exhaustion_calendar_years_assuming_continuous_cadence']}
nominal years at uninterrupted target cadence and no additional withdrawals.

Allocation selection grants no spending authority. Spending ceilings, operating
budgets, custody, recovery and formal Institute ratification remain unresolved.
The production template intentionally leaves spending limits unset. The proposed
300,000-GWD subsidy window ceiling is not an approved budget. Epoch 0 customer-funded
operator services must not draw independent-attestation subsidies.

Reproduce: `python3 scripts/compare-economic-designs.py`.
"""


if __name__ == '__main__':
    result = compare()
    check(result)
    out = ROOT / 'docs/economics'
    (out / 'ECONOMIC_DESIGN_COMPARISON.json').write_text(json.dumps(result, indent=2) + '\n')
    (out / 'ECONOMIC_DESIGN_COMPARISON.md').write_text(markdown(result))
    print(json.dumps({'status': 'PASS', 'scenario_categories': 5, 'designs': len(result['designs']), 'production_authorized': False}))
