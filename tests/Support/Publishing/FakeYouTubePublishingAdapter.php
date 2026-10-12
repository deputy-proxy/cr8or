<?php

namespace Tests\Support\Publishing;

final class FakeYouTubePublishingAdapter extends FakeNetworkPublishingAdapter
{
    protected function networkKey(): string
    {
        return 'youtube';
    }
}