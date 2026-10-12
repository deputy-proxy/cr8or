<?php

namespace Tests\Support\Publishing;

final class FakeThreadsPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'threads';
    }
}