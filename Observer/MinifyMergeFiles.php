<?php
/**
 * Copyright (c) 2022. MageCloud.  All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Observer;

use JShrink\Minifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class MinifyMergeFiles implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $data = $observer->getEvent()->getData('data');
        $content = $data->getContent();
        try {
            $extension = pathinfo($data->getFile(), PATHINFO_EXTENSION);
            if ($extension === 'html') {
                $content = preg_replace('!\s+!', ' ', $content);
            }
            if ($extension === 'js') {
                $content = Minifier::minify($data->getContent());
            }
        } catch (\Throwable $exception) {
            $content = $data->getContent();
        }

        $data->setContent($content);
    }
}
