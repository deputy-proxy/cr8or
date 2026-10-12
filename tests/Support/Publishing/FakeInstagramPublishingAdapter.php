<?php

namespace Tests\Support\Publishing;

final class FakeInstagramPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'instagram';
    }
}