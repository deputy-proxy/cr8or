<?php

namespace Tests\Support\Publishing;

final class FakeFacebookPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'facebook';
    }
}