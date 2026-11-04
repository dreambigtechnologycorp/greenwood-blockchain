<?php

declare(strict_types=1);

namespace Greenwood\Node;

/**
 * Greenwood V1 production chain configuration (economics + consensus cadence).
 *
 * Authoritative mapping of the finalized V1 economics onto the runtime domain:
 *
 *   block_time_seconds: 60          target block interval (no empty-block churn)
 *   hard_cap:           1,000,000,000 GWD   hard supply ceiling
 *   emission source:    security_infrastructure_reserve (rewards RELEASE from
 *                       this reserve; they never mint beyond the hard cap)
 *   fees:               20% permanent burn, 80% network/operator compensation
 *
 * Reward release and per-transaction fee disposition are exposed here as the
 * production schedule; they are NOT yet executed by the runtime as on-chain
 * mutation primitives. Those gaps are reported by the deployment tooling rather
 * than implemented as a second tokenomics engine.
 */
final class ChainConfig
{
    public const DEFAULT_BLOCK_TIME_SECONDS = 60;
    public const DEFAULT_HARD_CAP = 1_000_000_000;
    public const PLANNED_BLOCKS_PER_YEAR = 525960; // 365.25d * 24h * 60m, at 60s/block
    public const DEFAULT_EMISSION_SOURCE = 'security_infrastructure_reserve';
    public const DEFAULT_FEE_BURN_PERCENT = 20;
    public const DEFAULT_FEE_OPERATOR_PERCENT = 80;

    private int $blockTimeSeconds;
    private int $hardCap;
    private string $emissionSource;
    /** @var array<int, array{years_start:int,years_end:?int,reward_per_block:float}> */
    private array $emissionEpochs;
    private int $feeBurnPercent;
    private int $feeOperatorPercent;

    /** @param array<string,mixed> $data */
    public function __construct(array $data = [])
    {
        $this->blockTimeSeconds = self::intFrom($data, 'block_time_seconds', self::DEFAULT_BLOCK_TIME_SECONDS);
        if ($this->blockTimeSeconds < 1) {
            throw new \InvalidArgumentException('block_time_seconds must be >= 1');
        }

        $supply = is_array($data['supply'] ?? null) ? $data['supply'] : [];
        $this->hardCap = self::intFrom($supply, 'hard_cap', self::DEFAULT_HARD_CAP);
        if ($this->hardCap <= 0) {
            throw new \InvalidArgumentException('hard_cap must be positive');
        }

        $emission = is_array($data['emission'] ?? null) ? $data['emission'] : [];
        $this->emissionSource = (string) ($emission['source'] ?? self::DEFAULT_EMISSION_SOURCE);
        if ($this->emissionSource === '') {
            throw new \InvalidArgumentException('emission.source must not be empty');
        }
        $this->emissionEpochs = self::normalizeEpochs($emission['epochs'] ?? null);

        $fees = is_array($data['fees'] ?? null) ? $data['fees'] : [];
        $this->feeBurnPercent = self::intFrom($fees, 'burn_percent', self::DEFAULT_FEE_BURN_PERCENT);
        $this->feeOperatorPercent = self::intFrom($fees, 'operator_percent', self::DEFAULT_FEE_OPERATOR_PERCENT);
        if ($this->feeBurnPercent + $this->feeOperatorPercent !== 100 || $this->feeBurnPercent < 0 || $this->feeOperatorPercent < 0) {
            throw new \InvalidArgumentException('fees.burn_percent + fees.operator_percent must equal 100');
        }
    }
    /** @return array<int, array{years_start:int,years_end:?int,reward_per_block:float}> */
    private static function normalizeEpochs(?array $raw): array
    {
        if ($raw === null) {
            return self::defaultEpochs();
        }
        $epochs = [];
        $previousEnd = null;
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('emission.epochs must be a list of objects');
            }
            $start = self::intFrom($entry, 'years_start', -1);
            $end = self::nullableIntFrom($entry, 'years_end');
            $reward = (float) ($entry['reward_per_block'] ?? -1.0);
            if ($start < 0 || $reward <= 0 || ($end !== null && $end <= $start)) {
                throw new \InvalidArgumentException('invalid emission epoch');
            }
            if ($previousEnd !== null && $start !== $previousEnd) {
                throw new \InvalidArgumentException('emission epochs must be contiguous');
            }
            $epochs[] = ['years_start' => $start, 'years_end' => $end, 'reward_per_block' => $reward];
            $previousEnd = $end;
        }
        if ($epochs === [] || end($epochs)['years_end'] !== null) {
            throw new \InvalidArgumentException('emission must end with an open-ended epoch (years_end=null)');
        }
        return $epochs;
    }

    /** @return array<int, array{years_start:int,years_end:?int,reward_per_block:float}> */
    public static function defaultEpochs(): array
    {
        return [
            ['years_start' => 0, 'years_end' => 4, 'reward_per_block' => 10.0],
            ['years_start' => 4, 'years_end' => 8, 'reward_per_block' => 7.5],
            ['years_start' => 8, 'years_end' => 12, 'reward_per_block' => 5.0],
            ['years_start' => 12, 'years_end' => 16, 'reward_per_block' => 2.5],
            ['years_start' => 16, 'years_end' => null, 'reward_per_block' => 1.0],
        ];
    }

    public static function defaults(): self
    {
        return new self([]);
    }

    /** Load one authoritative configuration document (merge over defaults). */
    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Chain configuration not found: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Chain configuration must be a JSON object: {$path}");
        }
        return new self($data);
    }

    public function blockTimeSeconds(): int
    {
        return $this->blockTimeSeconds;
    }

    public function hardCap(): int
    {
        return $this->hardCap;
    }

    public function emissionSource(): string
    {
        return $this->emissionSource;
    }

    /** @return array<int, array{years_start:int,years_end:?int,reward_per_block:float}> */
    public function emissionEpochs(): array
    {
        return $this->emissionEpochs;
    }

    public function feeBurnPercent(): int
    {
        return $this->feeBurnPercent;
    }

    public function feeOperatorPercent(): int
    {
        return $this->feeOperatorPercent;
    }

    /** Planned block releases per year at the target block cadence. */
    public function blocksPerYear(): int
    {
        return self::PLANNED_BLOCKS_PER_YEAR;
    }
    /**
     * Reward (in GWD) ascribed to a block height under the production epoch
     * schedule. Year 0 begins at height 1. This is the schedule the runtime is
     * expected to enforce when reward release is implemented as a chain
     * primitive; it is constant/config-driven, never minted beyond the cap.
     */
    public function rewardPerBlockAtHeight(int $height): float
    {
        if ($height < 1) {
            throw new \InvalidArgumentException('reward height must be >= 1');
        }
        $year = intdiv($height - 1, self::PLANNED_BLOCKS_PER_YEAR);
        foreach ($this->emissionEpochs as $epoch) {
            $end = $epoch['years_end'];
            if ($end === null || $year < $end) {
                return $epoch['reward_per_block'];
            }
        }
        return (float) end($this->emissionEpochs)['reward_per_block'];
    }

    /** Approximate first-ful-year reserve release in GWD (year 0 schedule). */
    public function firstYearReserveRelease(): float
    {
        return $this->emissionEpochs[0]['reward_per_block'] * self::PLANNED_BLOCKS_PER_YEAR;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'block_time_seconds' => $this->blockTimeSeconds,
            'supply' => ['hard_cap' => $this->hardCap],
            'emission' => [
                'source' => $this->emissionSource,
                'releases_per_year' => self::PLANNED_BLOCKS_PER_YEAR,
                'first_year_reserve_release' => $this->firstYearReserveRelease(),
                'epochs' => array_map(
                    static fn (array $e): array => ['years_start' => $e['years_start'], 'years_end' => $e['years_end'], 'reward_per_block' => $e['reward_per_block']],
                    $this->emissionEpochs
                ),
            ],
            'fees' => ['burn_percent' => $this->feeBurnPercent, 'operator_percent' => $this->feeOperatorPercent],
        ];
    }

    /** @param array<string,mixed> $data */
    private static function intFrom(array $data, string $key, int $default): int
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        if (!is_int($data[$key])) {
            throw new \InvalidArgumentException("{$key} must be an integer");
        }
        return $data[$key];
    }

    /** @param array<string,mixed> $data */
    private static function nullableIntFrom(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_int($data[$key])) {
            throw new \InvalidArgumentException("{$key} must be an integer or null");
        }
        return $data[$key];
    }
}
