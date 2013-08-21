whiplash-magento
================

Whiplash Magento Extension, currently tested with Community Edition 1.6.2


Adds real-time synchronization of Products and Orders between Magento and Whiplash.

*Installation:*
- Create a directory called Whiplash in YourMagentoInstallation/app/code/community
- Place the Fulfillment directory into it
- Move Whiplash_Fulfillment.xml to YourMagentoInstalltion/app/etc/modules
- In the admin area of Magento, go to System -> Configuration -> General -> Whiplash
- Select API Configuration, enter your API Key, and Save Config

To test, save a product in Magento. It should be created or updated in your Whiplash account.