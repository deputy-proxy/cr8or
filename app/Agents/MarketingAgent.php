<?php

namespace App\Agents;

final class MarketingAgent extends Agent
{
    public function name(): string
    {
        return 'Marketing';
    }

    public function description(): string
    {
        return 'Coordinates governed marketing planning and content operations.';
    }

    public function responsibilities(): array
    {
        return ['plan marketing activity', 'coordinate marketing expertise', 'protect content governance'];
    }

    public function capabilities(): array
    {
        return ['marketing.plan', 'marketing.content.create', 'marketing.content.update', 'marketing.content.review', 'marketing.content.publication-ready'];
    }

    public function requiredContext(): array
    {
        return ['enterprise', 'strategy', 'knowledge'];
    }
}