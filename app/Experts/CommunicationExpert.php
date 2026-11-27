<?php

namespace App\Experts;

final class CommunicationExpert extends Expert
{
    public function definition(): ExpertDefinition
    {
        return new ExpertDefinition(
            name: 'Communication',
            description: 'Applies controlled outbound communication methods to authorized enterprise notifications.',
            responsibilities: ['prepare operational notifications', 'deliver authorized outbound messages'],
            methodology: 'Recipient identity is resolved from the authenticated actor; delivery uses the configured application mail transport.',
            requiredContext: ['enterprise', 'actor'],
            capabilities: ['communication.email.send'],
        );
    }

    public function analyze(array $context): array
    {
        return [
            'focus' => 'controlled communication',
            'available_context' => array_keys($context),
        ];
    }
}
