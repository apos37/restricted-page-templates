=== Restricted Page Templates ===
Contributors: apos37
Tags: restrict content, access control, user roles, members only, access denied
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restrict pages by role, user or login status, and show every blocked visitor a customizable template that explains why and what to do next.

== Description ==

Most access plugins hide a page and show one generic "access denied" line. **Restricted Page Templates** lets you decide who can view each page, post or custom post type, and then shows each blocked visitor a message written for them.

Visitors who cannot view something are sent to your No Access Page. There, the plugin picks the template that best matches the page they tried to open and the reason they were stopped, and fills it in with links to log in, register, log out or try again.

= Customizable templates =

Templates are the heart of the plugin. Each one is a regular post you write in the block editor or the Classic Editor, with full formatting, images, buttons and columns.

* **Write a template for each situation:** the visitor needs to log in, the user lacks the required role or user access, a logged-in user opened a page meant for logged-out visitors, or the link does not match the page's URL Parameter rule.
* **Choose where it applies:** use a template everywhere, for certain post types, or for specific posts and pages.
* **Templates for specific posts follow each post's Access setting:** they show to whoever that post blocks, or you can limit them to certain situations.
* **The best match wins:** a template for a specific post beats one for the post type, which beats a global one. If two tie, the most recently edited one is used.
* **Merge tags:** drop in login, logout, register, retry and homepage links or URLs, the title and URL of the restricted page, the roles that can view it, the visitor's name and the site name. Tags also work inside link and button URLs, and visitors return to the page they wanted after logging in.
* **See what visitors will see:** the Access box lists the templates in use and updates as you change the access, before you save. Optionally list them in the post list's Access column too, with unique templates marked.
* **Safe defaults:** four ready-made templates are created for you, and a built-in message is used whenever no template matches.

= An overview of everything restricted =

The **Pages** screen counts the restricted content in each post type, split into Logged-In Only, Logged-Out Only, URL Parameter and Everyone. Expand a post type to list its restricted posts, their access and the templates blocked visitors will see.

= Flexible access rules =

* **Per post:** Everyone, Logged-In Only, Logged-Out Only or URL Parameter, from a box on the edit screen, Quick Edit or Bulk Edit.
* **By role or by user:** limit "Logged-In Only" content to certain roles, to specific users, or to both.
* **By URL parameter:** let anyone view the content when the link they arrive with matches a rule: a parameter is, is not, contains, does not contain, starts with, ends with, is greater than or is less than a value. A missing parameter counts as empty, so "id is not" with no value only opens the page for links that carry an id.
* **Post type defaults:** choose which post types have access controls and the access their content uses unless a post says otherwise.
* **Administrator bypass:** optionally let administrators view everything.

= Hide restricted content everywhere =

Restricted content is always protected when visited directly. You can also remove it, for anyone who cannot view it, from:

* Archives, the blog page and post lists, including Query Loop blocks and most page builder widgets
* Search results
* RSS feeds
* The REST API
* XML sitemaps from WordPress, Yoast SEO, Rank Math, All in One SEO and SEOPress
* Previous and next post links
* Navigation menus, in Appearance > Menus and in the Navigation block, where items linking to content can simply match that content's access

= Lightweight =

* Access rules are kept in one cached index, so hiding content adds no post meta joins to your queries.
* Scripts and styles only load on the screens that use them.
* No extra database tables, no external requests and no upsells.

= For developers =

Actions and filters are available throughout, for example `rptemplates_user_has_access`, `rptemplates_access_rule`, `rptemplates_template_id`, `rptemplates_template_content`, `rptemplates_merge_tags`, `rptemplates_template_allowed_html` and `rptemplates_redirect_url`. See the developer docs for the full list.

= Getting Started =

1. Go to **Restricted Pages > Settings** and select or create your **No Access Page**. It must contain the `[restricted_page_template]` shortcode.
2. Under **Post Types**, enable the post types you want to control and choose their default access.
3. Edit a post or page and choose who can view it in the **Access** box.
4. Customize what blocked visitors see under **Restricted Pages > Templates**.
5. Check everything that is restricted under **Restricted Pages > Pages**.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the `restricted-page-templates` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to Restricted Pages > Settings to finish setup.

== Frequently Asked Questions ==

= Why can visitors still view restricted pages? =
Visitors are only redirected once a published No Access Page is selected under Restricted Pages > Settings. Also check that the post type is enabled under Post Types.

= How does the plugin choose which template to show? =
When a visitor is redirected, the No Access Page looks at the restricted post, the link the visitor arrived with, and the reason they were blocked: they need to log in, they lack the required role or user access, they are logged in on a logged-out-only page, or their link does not match the page's URL Parameter rule. A template for that specific post wins over one for its post type, which wins over a global one. If nothing matches, a built-in default is used.

= How does URL Parameter access work? =
Choose URL Parameter as the access for a post, or as a post type's default, then enter a parameter name, a condition and a value. Visitors whose link matches can view the content, logged in or not, and everyone else is sent to the No Access Page and shown your "URL Parameter Not Met" templates. For example, "id is not" with no value requires links like `?id=123`, while "ref is email" only opens the page from your email links. When hiding restricted content from archives, search, feeds, sitemaps and menus, links are checked without parameters, so the content is listed only if its rule matches an empty parameter. WordPress query names, such as p, s and page_id, can't be used.

= Can I show a different message on one page? =
Yes. In the Access box on that page, click "Create a unique template for this post". Alternatively, create a new template and choose "Specific Posts" as the Scope and choose the page you want. Then select "Follows each post's Access setting." You will still need to select the Access point on the page itself if you don't want it set to the default.

= Which merge tags are available? =
{login_link}, {login_url}, {logout_link}, {logout_url}, {register_link}, {register_url}, {retry_link}, {retry_url}, {home_link}, {home_url}, {post_title}, {post_url}, {required_roles}, {user_display_name} and {site_name}. The Merge Tags box on the template screen lists them all, and developers can add more with the `rptemplates_merge_tags` filter.

= Will hiding restricted content work with my theme and plugins? =
Restricted content is always protected when visited directly. The optional hiding features depend on how your theme and other plugins load content:

* **Archives, search and feeds** cover queries made through WordPress, including Query Loop blocks and most page builder widgets. Content loaded with custom database queries, or with `suppress_filters` turned on, is not affected.
* **Front-end AJAX** requests, such as "load more" buttons, are not filtered by default. Developers can add `'rptemplates_context' => 'archives'` to the query arguments to include them.
* **XML sitemaps** are supported for WordPress core, Yoast SEO, Rank Math, All in One SEO and SEOPress. Other sitemap generators may still list restricted content. Remember to clear your SEO plugin's sitemap cache after changing access.
* **Navigation menus** work in Appearance > Menus and in the Navigation block. Menus built by page builders or other menu plugins may not be affected. The Customizer's menu editor does not show the visibility controls.
* **Previous/next links** work with WordPress functions and blocks. Themes that build these links with their own queries may still show restricted posts, but those posts are still protected when clicked.

= Is the hiding feature fast on large sites? =
Yes. Access rules are kept in a single cached index, so no post meta joins are added to your queries. For the best performance, restrict whole post types with the default access and allow exceptions per post, rather than restricting thousands of posts one by one.

= Does it work with caching plugins? =
Restricted pages redirect before any content is sent, and the No Access Page tells caching plugins not to cache it. If a page cache serves logged-out visitors, clear it after changing access rules.

= What happens to my data when I delete the plugin? =
Nothing is removed unless you check "Remove All Plugin Data on Uninstall" under Settings > Advanced.

== Screenshots ==

1. The Pages overview, with a post type expanded to show its restricted posts and templates
2. Writing a template in the block editor, with its settings and merge tags
3. The templates list, showing when and where each template applies
4. Settings
5. The Access box on the edit screen, with roles, specific users and the templates in use
6. The Access column, with templates shown, and Quick Edit
7. Menu item visibility

== Changelog ==
= 1.0.1 =
* Initial release.