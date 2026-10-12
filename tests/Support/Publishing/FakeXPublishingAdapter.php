<?php

namespace Tests\Support\Publishing;

final class FakeXPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'x';
    }
}