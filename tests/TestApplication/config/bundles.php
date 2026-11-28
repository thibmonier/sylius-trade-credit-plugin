<?php

return [
    Tmonier\SyliusOrderHoldPlugin\TmonierSyliusOrderHoldPlugin::class => ['all' => true],
    Tmonier\SyliusTradeCreditPlugin\TmonierSyliusTradeCreditPlugin::class => ['all' => true],
    // US-304: the probe of this plugin is loaded because the health plugin is installed in the test application.
    Tmonier\SyliusPlatformHealthPlugin\TmonierSyliusPlatformHealthPlugin::class => ['all' => true],
];
