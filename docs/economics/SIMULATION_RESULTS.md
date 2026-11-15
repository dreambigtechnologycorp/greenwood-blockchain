# Candidate economics simulation

Offline arithmetic only; no production authorization. Years use 365.25 days.

## Confirmed policy: 60-second target blocks

| Assumed block interval | First 16 nominal years: scheduled GWD | Reserve exhaustion (calendar years) |
|---|---:|---:|
| 60 seconds | 52596000.000000 | 201.192790 |

At the established 60-second target, the first sixteen nominal years release 52,596,000 GWD, leaving 97,404,000 GWD under the selected 15/25/30/25/5 allocation. The table reports the theoretical reserve runway; this is token arithmetic, not proof of sustainable operating costs.

## Counterfactual sensitivity only — not proposed block-interval changes

| Actual continuous block interval | First four epochs (calendar years) | Reserve exhaustion (calendar years) |
|---|---:|---:|
| 60 seconds | 16.000000 | 201.192790 |
| 10 seconds | 2.666667 | 33.532132 |
| 6 seconds | 1.600000 | 20.119279 |

The 10- and 6-second rows below are counterfactual diagnostics, not unresolved policy choices. These rows use the same fixed block epochs. The first four epochs release 52,596,000 GWD in every row; faster blocks change the elapsed calendar time, not this amount.

## GRU settlement examples

| Service GRU | Hypothetical USD/GWD | GWD required |
|---:|---:|---:|
| 1000 | 0.005 | 2000.000000 |
| 1000 | 0.01 | 1000.000000 |
| 1000 | 0.05 | 200.000000 |
| 1000 | 0.10 | 100.000000 |
| 1000 | 1.00 | 10.000000 |

The 5,000-GRU example at $0.01/GWD with an 80% subsidy pays 5,000 GWD to the provider: 4,000 from subsidy funding and 1,000 from the user. Network transaction fees are separate and are not burned.

## Budget window equivalents


No USD purchasing power, market price, revenue forecast or sustainable provider margin is established by these calculations.
