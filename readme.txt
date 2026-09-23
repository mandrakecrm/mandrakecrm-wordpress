=== MandrakeCRM – E-commerce CRM & AI Marketing Automation ===
Contributors: mandrakecrm
Tags: woocommerce, crm, email-marketing, abandoned-cart, marketing-automation
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 3.11
WC requires at least: 8.0
WC tested up to: 9.5
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recover abandoned carts. Email marketing campaigns. Track campaign ROI. Connect your store in minutes. Start free 7-day trial.

== Description ==

**70% of your customers add items to cart and disappear. You're losing thousands in sales every month.**

MandrakeCRM fixes this automatically. Connect your WooCommerce store and start recovering lost revenue in minutes - no marketing experience needed.

= The Problem =

You're a store owner, not a marketer. But you know:
* Customers abandon carts and never come back
* Your emails look generic and unprofessional
* You have no idea which marketing actually works
* You're losing sales every single day

= The Solution =

MandrakeCRM handles marketing automatically while you focus on your business:

**Stop Losing Sales**
Automatically send recovery emails to customers who abandon carts. Most stores recover 15-30% of lost revenue.

**Look Professional**
Replace generic WooCommerce emails with beautiful branded templates. No designer needed.

**Know What Works**
See exactly which Facebook ad, email campaign, or discount code drives each sale.

**Grow Your List**
Smart popups capture email addresses before visitors leave your site.

= Why Store Owners Choose MandrakeCRM =

**"Set it and forget it"** - Takes 5 minutes to connect. Runs automatically 24/7.

**"No per-contact fees"** - Unlike Klaviyo or Mailchimp, you don't pay more as your list grows.

**"Actually easy to use"** - Built for store owners, not marketers. No technical skills required.

**7-day free trial** - See results before you pay. No credit card required.

👉 **[Start Free Trial at mandrakecrm.com](https://www.mandrakecrm.com)**

*"Recovered $4,200 in the first month. I'm not technical at all - setup was actually 15 minutes."*
— Jennifer M., Miami

*"Nossa lista cresceu 400% e o preço continua o mesmo."*
— Ricardo S., São Paulo

*"Los emails se ven profesionales sin diseñador."*
— Martín G., Buenos Aires

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > MandrakeCRM to configure the plugin
4. Enter your MandrakeCRM token (get one at [www.mandrakecrm.com](https://www.mandrakecrm.com))
5. Enable the features you want to use

== Frequently Asked Questions ==

= Do I need a MandrakeCRM account? =

Yes. Visit [www.mandrakecrm.com](https://www.mandrakecrm.com) for a 7-day free trial - no credit card required.

= How long does setup take? =

5-10 minutes. Install plugin, paste your token, activate features. Done.

= Which emails are replaced by MandrakeCRM? =

9 customer emails: New Account, Order Processing, Completed, On Hold, Refunded, Cancelled, Failed, Customer Note, and Password Reset. Admin emails stay with WooCommerce.

= Does it track guest checkout carts? =

Yes. Tracks both registered users and guest checkouts for abandoned cart recovery.

= How does campaign attribution work? =

Automatically captures UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content) from visitor URLs AND tracks coupon codes used. When they purchase, you see exactly which campaign or discount code drove the sale.

= Is it compatible with HPOS and Block Checkout? =

Yes. Fully compatible with WooCommerce High-Performance Order Storage and modern block-based checkout.

= Will this slow down my site? =

No. Async processing on cloud servers means zero performance impact on your store.

= Where can I get support? =

Visit [www.mandrakecrm.com](https://www.mandrakecrm.com) for documentation and support in English, Spanish, and Portuguese.

== Screenshots ==

1. Plugin Settings Page – Connect your WooCommerce store and activate features with one-click toggles
2. MandrakeCRM Dashboard – Track revenue recovered, emails sent, and campaign performance in real-time
3. Customer Segmentation – Create smart segments to target the right customers with personalized campaigns

== Changelog ==

= 3.11 =
* Improved marketing copy and user-facing text consistency
* Updated terminology: "Marketing Attribution" instead of "UTM tracking"
* Enhanced disconnect modal text to emphasize sales impact
* Aligned subtitle with brand messaging from mandrakecrm.com
* Added abandoned cart feature to documentation
* Updated transactional emails list to include all 9 WooCommerce email types
* Improved admin interface text to focus on benefits over features
* Complete i18n implementation at 100%
* Updated Spanish and Portuguese translations
* Genericized technical comments for better code maintainability

= 2.0.0 =
* Complete plugin architecture with OOP standards
* Added HPOS compatibility
* Added Block Checkout support
* Modern admin UI
* Improved security with nonces and capability checks
* Daily sync system to track plugin status
* Enhanced UTM tracking with additional parameters (gclid, fbclid)

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Major update with improved architecture, HPOS support, and enhanced features. Please backup before updating.

== Privacy Policy ==

MandrakeCRM connects to external services to provide its functionality. By using this plugin, you acknowledge that:

* Customer data (email addresses, names, order information) is transmitted to MandrakeCRM servers for CRM management
* Transactional emails are sent through MandrakeCRM's infrastructure
* Marketing consent data is stored and processed according to your configured settings

For full privacy details, visit: https://mandrakecrm.com/en/privacy-policy

== External Services ==

This plugin connects to the following external services:

**MandrakeCRM API** (https://pzjjfecnkiaaniwtlydr.supabase.co)
- Purpose: CRM data synchronization, transactional email delivery
- Data sent: Customer emails, names, order details, marketing preferences
- Privacy: https://mandrakecrm.com/en/privacy-policy
- Terms: https://mandrakecrm.com/en/terms-of-service

**MandrakeCRM CDN** (https://cdn.mandrakecrm.io)
- Purpose: Static assets (logos, images) for email templates
- Data sent: None (read-only assets)

No data is transmitted without your explicit configuration of the plugin.
