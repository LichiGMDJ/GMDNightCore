<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

final class TournamentSystem
{
    public function __construct(
        private TournamentService $core,
        private TournamentCompetitionService $competition,
        private TournamentViewRepository $view,
        private TournamentFeaturePolicy $features
    ) {
    }

    public function core(): TournamentService { return $this->core; }
    public function competition(): TournamentCompetitionService { return $this->competition; }
    public function view(): TournamentViewRepository { return $this->view; }
    public function features(): TournamentFeaturePolicy { return $this->features; }
}
