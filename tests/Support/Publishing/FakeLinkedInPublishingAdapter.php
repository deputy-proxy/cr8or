<?php

namespace Tests\Support\Publishing;

final class FakeLinkedInPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function network(): string
    {
        return 'linkedin';
    }
}