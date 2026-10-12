<?php

namespace Tests\Support\Publishing;

final class FakeInstagramPublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function network(): string
    {
        return 'instagram';
    }
}