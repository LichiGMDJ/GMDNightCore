<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;
use NightCore\Core\Config;
use RuntimeException;

final class TournamentFeaturePolicy
{
    public const ENABLED = 'enabled';
    public const READ_ONLY = 'read_only';
    public const DISABLED = 'disabled';

    public function __construct(
        private string $tournamentsMode,
        private string $predictionsMode
    ) {
        $this->tournamentsMode = self::normalize($tournamentsMode, 'TOURNAMENTS_MODE');
        $this->predictionsMode = self::normalize($predictionsMode, 'TOURNAMENT_PREDICTIONS_MODE');
    }

    public static function fromEnvironment(): self
    {
        return new self(
            Config::get('TOURNAMENTS_MODE', self::DISABLED) ?? self::DISABLED,
            Config::get('TOURNAMENT_PREDICTIONS_MODE', self::DISABLED) ?? self::DISABLED
        );
    }

    public function tournamentsMode(): string
    {
        return $this->tournamentsMode;
    }

    public function predictionsMode(): string
    {
        return $this->predictionsMode;
    }

    public function requireTournamentRead(): void
    {
        if ($this->tournamentsMode === self::DISABLED) {
            throw new RuntimeException('Tournament feature is disabled.');
        }
    }

    public function requireTournamentWrite(): void
    {
        if ($this->tournamentsMode !== self::ENABLED) {
            throw new RuntimeException('Tournament feature is not writable.');
        }
    }

    public function requirePredictionRead(): void
    {
        $this->requireTournamentRead();
        if ($this->predictionsMode === self::DISABLED) {
            throw new RuntimeException('Tournament predictions are disabled.');
        }
    }

    public function requirePredictionWrite(): void
    {
        $this->requireTournamentWrite();
        if ($this->predictionsMode !== self::ENABLED) {
            throw new RuntimeException('Tournament predictions are not writable.');
        }
    }

    public function requirePredictionClaim(): void
    {
        $this->requirePredictionRead();
    }

    private static function normalize(string $mode, string $key): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::ENABLED, self::READ_ONLY, self::DISABLED], true)) {
            throw new InvalidArgumentException(
                $key . ' must be one of: enabled, read_only, disabled.'
            );
        }
        return $mode;
    }
}
