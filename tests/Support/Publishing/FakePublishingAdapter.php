<?php

namespace Tests\Support\Publishing;

final class FakePublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'linkedin';
    }
}