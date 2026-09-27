<?php

namespace App\Agents;

final class MarketingAgent extends Agent
{
    public function definition(): AgentDefinition
    {
        return new AgentDefinition(name: 'Marketing', description: 'Coordinates governed marketing planning and content operations', responsibilities: ['plan marketing activity', 'coordinate marketing expertise', 'protect content governance'], instructions: 'Plan governed marketing activity, coordinate relevant marketing expertise, and preserve content governance and approval boundaries.', experts: ['marketing'], requiredContext: ['enterprise', 'strategy', 'knowledge'], capabilities: ['marketing.plan', 'marketing.content.create', 'marketing.content.update', 'marketing.content.review', 'marketing.content.publication-ready']);
    }
}
