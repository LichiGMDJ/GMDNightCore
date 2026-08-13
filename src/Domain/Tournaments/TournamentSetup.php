<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;

final class TournamentSetup
{
    private string $slug;
    private string $name;
    private string $description;
    private string $rulesText;
    private int $predictionOpensAt;
    private int $predictionLocksAt;
    private int $startsAt;
    private int $endsAt;
    private int $matchPickPoints;
    private int $championPickPoints;
    /** @var array<string,mixed> */
    private array $matchPickReward;
    /** @var array<string,mixed> */
    private array $championPickReward;

    /** @param array<string,mixed> $data */
    private function __construct(array $data)
    {
        $this->slug = (string) $data['slug'];
        $this->name = (string) $data['name'];
        $this->description = (string) $data['description'];
        $this->rulesText = (string) $data['rulesText'];
        $this->predictionOpensAt = (int) $data['predictionOpensAt'];
        $this->predictionLocksAt = (int) $data['predictionLocksAt'];
        $this->startsAt = (int) $data['startsAt'];
        $this->endsAt = (int) $data['endsAt'];
        $this->matchPickPoints = (int) $data['matchPickPoints'];
        $this->championPickPoints = (int) $data['championPickPoints'];
        $this->matchPickReward = $data['matchPickReward'];
        $this->championPickReward = $data['championPickReward'];
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $rulesText = trim((string) ($input['rulesText'] ?? ''));

        if ($slug === '' || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/D', $slug) !== 1) {
            throw new InvalidArgumentException('Tournament slug must contain only lowercase letters, numbers and hyphens.');
        }
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Tournament name must contain 1-120 bytes.');
        }
        if (strlen($description) > 2000) {
            throw new InvalidArgumentException('Tournament description is too long.');
        }
        if (strlen($rulesText) > 12000) {
            throw new InvalidArgumentException('Tournament rules are too long.');
        }

        return new self([
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'rulesText' => $rulesText,
            'predictionOpensAt' => self::nonNegativeInt($input['predictionOpensAt'] ?? 0),
            'predictionLocksAt' => self::nonNegativeInt($input['predictionLocksAt'] ?? 0),
            'startsAt' => self::nonNegativeInt($input['startsAt'] ?? 0),
            'endsAt' => self::nonNegativeInt($input['endsAt'] ?? 0),
            'matchPickPoints' => self::boundedInt($input['matchPickPoints'] ?? 1, 0, 100000),
            'championPickPoints' => self::boundedInt($input['championPickPoints'] ?? 5, 0, 100000),
            'matchPickReward' => self::reward($input['matchPickReward'] ?? []),
            'championPickReward' => self::reward($input['championPickReward'] ?? []),
        ]);
    }

    public function slug(): string { return $this->slug; }
    public function name(): string { return $this->name; }
    public function description(): string { return $this->description; }
    public function rulesText(): string { return $this->rulesText; }
    public function predictionOpensAt(): int { return $this->predictionOpensAt; }
    public function predictionLocksAt(): int { return $this->predictionLocksAt; }
    public function startsAt(): int { return $this->startsAt; }
    public function endsAt(): int { return $this->endsAt; }
    public function matchPickPoints(): int { return $this->matchPickPoints; }
    public function championPickPoints(): int { return $this->championPickPoints; }

    /** @return array<string,mixed> */
    public function matchPickReward(): array { return $this->matchPickReward; }

    /** @return array<string,mixed> */
    public function championPickReward(): array { return $this->championPickReward; }

    private static function nonNegativeInt(mixed $value): int
    {
        $value = is_numeric($value) ? (int) $value : 0;
        return max(0, $value);
    }

    private static function boundedInt(mixed $value, int $min, int $max): int
    {
        $value = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $value));
    }

    /** @return array<string,mixed> */
    private static function reward(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 8000) {
            throw new InvalidArgumentException('Tournament reward payload is too large or invalid.');
        }
        return $value;
    }
}
