<?php

namespace Tests\Support\Publishing;

final class FakeLinkedInPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'linkedin';
    }
}