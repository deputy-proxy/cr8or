<?php

namespace Tests\Support\Publishing;

final class FakeTikTokPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'tiktok';
    }
}