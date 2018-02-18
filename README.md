# Commerce SecurePay AU 8

This module is initial port of commerce_securepayauau 7.x version.


# Scope

Commerce SecurePay is a payment gateway module for Drupal Commerce 2 that allows you to process credit card payments on your site using SecurePay payment service. 

Please not that at the moment only once-off payments are supported with **SecurePay XML API**. 

All other tasks like refunds and deletion should be performed on Securepay.com.au merchant account facility.

## Installation

Use Drupal standard way to install the module.

Requirements :
 - Commerce 
 - Commerce Payment
 - You will require a merchant account with securepay.com.au to accept payments

Run following composer command to download the module.

    composer require drupal/commerce_securepayau

## 
Enable module with drush or administration UI.
