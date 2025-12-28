# Lawnding Page
A single-page, responsive, flatfile no-cms landing site platform for various types of online communities.

The project is intended to be a bit more robust than a simple carrd site, but easy for people to work with and modify/expand on.  It's feasible to even create a simple site based off of this.

It's created with HTML wrapped in PHP, as well as CSS and JS.

## Getting Started
~~After cloning the repo, drop everything in your site's root.  As long as PHP is installed, running index.php should do the trick!~~
### File Structure
To set up LawndingPage, understand the structure of the files.

```
- admin/
    - users.json
    - auth.php
    - config.php
- public/
    - admin/
        - index.php
    - res/
        - data/
            - header.json
            - links.json
            - about.md
            - faq.md
            - rules.md
        - img/
            - logo.jpg
            - (other images)
        - scr/
            - app.js
            - config.js
            - jquery-#.#.#.min.js
            - Parsedown.php
            - save-config.php
        - config.css
        - style.css
    - index.php
    - LICENSE.md
    - README.md
    - THIRD-PARTY-LICENSES.md
- lp-bootstrap.php
- lp-overrides.php (optional)
```

- `public/` is the main site.  If you don't have a pre-configured web server, this should be your website root.
- `admin/` contains the important files for user authentication.
- `lp-bootstrap.php` provides runtime path defaults.  `lp-overrides.php` is optional and can override defaults (i.e. if your website root is in `public_html/` instead of just `public/`).

### Quick Start
For this walkthrough, we'll assume your website is `http://www.awesomelandingpage.com/` because clearly your landing page is going to be the most awesome page ever.

For purposes of this walkthrough, it's assumed you've got a web host situated, and you own a domain name.  It's also assumed you've downloaded/cloned this repo.

If you don't have an existing web site, then drop everything into your server's root directory, make sure `public/` is set as the website's root.  The `admin/` folder that is OUTSIDE of `public/` should stay outside of the public folder, in your server's root.  This should ensure it cannot be accessed from the internet and only internal scripts cna touch it.  Now if you go to the website, it'll display the default page.  Note, for some shared hosting, they already have a public folder.  I use Hostinger, and their website root is named `public_html/`, so you can copy everything in this project's `public/` folder into that one.  The website should display with a logo placeholder, a title, subtitle, along with one or two panes, depending on if you're on mobile or desktop.  Navigation bar should be on the bottom.  If that's good, we can move on.

At the end of the url, enter `/admin` at the end of the website.  A login page should appear.  As this is the first time you're using it, you will be prompted to create a master admin account.  This allows full access to the site, including any future functions pertaining to the very back-end of the site.  Then, you'll be prompted to log-in to the admin panel.

The admin panel will appear similar to the site, with a few extra buttons in the header.  Ideally you'll want a normal full-admin account.  To create one - and any future accounts, go to the user management page (leftmost button), and under "Create user", enter a username and temporary password.  Click the "Create User" button, and the new user should appear underneath the master account with the temporary password displayed so you can copy/paste it to give to the new user,  Click the "Permissions" button next to the new user and set it to "Full admin".  Log out of the master account, sign in as the new user, create a new password, and you're good to go!

From there, you can add links, edit the title and subtitle of the page, the logo, add other users, remove them, add and remove background images, and modify the text contents of each page.  Once you're done editing the pages, click the "Save All Changes" button.  Once you see the confirmation message at the top of the screen, go back to your web page and the changes should be instant.

TODO: Add a proper write-up for the admin health check warnings and what they mean.
TODO: Add guidance on checking `admin/errors.txt` for troubleshooting.

## Overview
Lawnding page uses a straightforward system to display content, known as "panes" or "panels".

The header panel is a standard full-width display, that shows the logo of the group, the title, and a subtitle.

Depending on the display mode (desktop or mobile), users will see 1 or 2 panes for the content.  A navbar is displayed at the bottom, with any footer text displayed at the bottom.

The first pane is for Links.  With many community landing pages, links to any groups, chats, forums, etc. are fairly prominent, so this is the main page that is viewed, similar to communities that use something like a linktree or carrd site.  In desktop mode, this pane is always visible, and is absent from the navbar.  In mobile mode, it's the first pane that is displayed, and an option to select it appears in the navbar.

Next, the About pane is visible.  This is a simple page that lets a community give a blurb about their community and what they're about.  In desktop mode, it's the default for the right pane.  It's composed of markdown that is rendered to the screen.

The third pane is Rules.  Just like About, this uses markdown to populate the pane and describe a list of rules, code of conduct, etc...

The fourth pane is for FAQ, and allows the community to host series of questions they're commonly asked, stored in markdown.

The fifth and sixth panes are for Events and Donate buttons, respectively.  It's currently unfinished, and will either import data from a calendar, or will simply link to a calendar or external events page.  The Donate button will either show a page for donations, or simply link to a donate page i.e. PayPal or something.

Everything public-facing is contained within `index.php` as it's handled as a single page website, instead of storing a bunch of separate pages.  Styling is handled within `style.css` and takes care of both the main page and the config page.  All interactivity is contained in `app.js`.

To keep things visually consistent, `config.php` is derived off the main page, and allows users to configure every part of what currently exists.  They can set background images, the logo, the title, subtitle, add and remove links/separators, and modify the contents of each page (except for EVENTS and DONATE).

## Page Breakdown
Currently, pages are hardcoded into index.php, and simply use some php code nested inside of HTML code to pull content from the dedicated markdown files.  For example, the server will populate the contents of `about.md` and place it inside of the content for the About Pane.  Eventually, this will be turned into a fully modular system, not unlike say, Wordpress sites, where the end user has full control from within the configuration page to add and remove panes from the site.  That means, if someone wanted to modify this into their personal website, they will soon be able to simply configure the site through config.php, including adding or removing panes, changing styles, and so-on.

Until then, it's still possible, but they will need to edit the page manually and potentially account for it in the config file as well.

### `index.php`
Before we even get to the HTML part of this page, there's a ton of environment variables that we set, ensuring everything runs smoothly:

```php
<?php
require_once __DIR__ . '/../lp-bootstrap.php';
require __DIR__ . '/res/scr/Parsedown.php';

$rulesMdPath = __DIR__ . '/res/data/rules.md';
$rulesMarkdown = is_readable($rulesMdPath) ? file_get_contents($rulesMdPath) : '';
$Parsedown = new Parsedown();
$rules = $Parsedown->text($rulesMarkdown);

$aboutMdPath = __DIR__ . '/res/data/about.md';
$aboutMarkdown = is_readable($aboutMdPath) ? file_get_contents($aboutMdPath) : '';
$about = $Parsedown->text($aboutMarkdown);

$faqMdPath = __DIR__ . '/res/data/faq.md';
$faqMarkdown = is_readable($faqMdPath) ? file_get_contents($faqMdPath) : '';
$faq = $Parsedown->text($faqMarkdown);

$linksJsonPath = __DIR__ . '/res/data/links.json';
$linksData = [];
if (is_readable($linksJsonPath)) {
    $decoded = json_decode(file_get_contents($linksJsonPath), true);
    if (is_array($decoded)) {
        $linksData = $decoded;
    }
}

$headerJsonPath = __DIR__ . '/res/data/header.json';
$headerData = [
    'logo' => 'res/img/logo.jpg',
    'title' => 'Long Island Furs',
    'subtitle' => 'A Long Island furry community encompassing Queens, Nassau County, and Suffolk County.  And Staten Island, but we do not talk about that.',
    'backgrounds' => ['res/img/bg.jpg']
];
if (is_readable($headerJsonPath)) {
    $decoded = json_decode(file_get_contents($headerJsonPath), true);
    if (is_array($decoded)) {
        $headerData = array_merge($headerData, $decoded);
    }
}
?> 
```

Firstly, we require Parsedown, as that's the tool that makes this whole thing work.  It takes the markdown files and parses them, turning it into valid HTML so it looks all pretty and nice.  For the default configuration of Lawnding Page, you can see that we load the rules, about, and links files and parse them.  A keen eye would see I mentioned we load the links file in the same paragraph I'm talking about Parsedown, even though it's very clearly a json file.

Bite me.

The PHP parses the JSON files as well - for the links and the header information, after all of that, it populates the data as we get lower down in the page.

#### Header & Background
The header has 3 parts, plus the background.  The data for this is stored in `res/data/header.json`:
```json
{
    "logo": "res/img/logo.jpg",
    "title": "My Amazing Community",
    "subtitle": "My community is better than your community.  Suck it, Trebek.",
    "backgrounds": [
        {
            "url": "res/img/bg.jpg",
            "author": "Bob Ross"
        }
    ]
}
```
1. `"logo"`
    - The logo is a 128px square image.  Any image will be resized to fit within a 128x128 px square.  Any image type is allowed.  It gets applied to the style, so use a file path.  No need to use `url("path/to/file")`, just the path in quotes will suffice.
2. `"title"`
    - The title is placed within a `<h1>` tag and ideally contains the name of your community.  Just use a string, here.
3. `"subtitle"`
    - The subtitle is placed within `<h2>` tags and ideally you'll include some sort of snazzy, catchy phrase under it.  Same as with `"title"`, just use a string in quotes.
4. `"backgrounds"`
    - The Background(s) are applied to `<body>` and are designed to cover the div, ensuring the image will always fill the page.  If you select background images that have prominent subject matters, consider keeping it central to the photo so whether it's on mobile (portrait) or desktop (landscape) modes, it'll be seen.
    - Multiple backgrounds can be listed as their own objects, separated by commas.  For each object there should be two key/value pairs.  The `"url"` contains the path to the image, just like with `"logo"`, and `"author"` contains a string with the name of who that photo belongs to/who took it.  If no name is specified, it'll default to displaying "anonymous".
    - This text is displayed at the bottom of the site as "`Background image by <name>`".

#### Container and Panes
The `div` named `#container` contains all of the panes.  Or panels.  Or divs.  Panes, panels, it's all royal *pain*.  Ha ha ha, very funny.
Whatever you want to call them, they are the meat and potatoes of the site, and allow you to get information to your users.  The majority of the panes are simply text content, but some panes have specific behaviors.

Here's an example of two panes - a Link List, and a normal pane.

```php
        <div class="pane glassConvex alwaysShow" id="links">
            <h3>LINKS</h3>
            <ul class="linkList" id="linkList">
                <?php foreach ($linksData as $link): ?>
                    <?php if (($link['type'] ?? '') === 'separator'): ?>
                        <li class="separator" aria-hidden="true"><hr></li>
                    <?php elseif (($link['type'] ?? '') === 'link'): ?>
                        <?php
                            $href = $link['href'] ?? '#';
                            $title = $link['title'] ?? '';
                            $text = $link['text'] ?? '';
                            $id = $link['id'] ?? '';
                            $isFullWidth = !empty($link['fullWidth']);
                            $isCta = !empty($link['cta']);
                            $liClasses = trim(($isFullWidth ? 'fullWidth ' : '') . 'linkItem');
                            $aClasses = trim('link linkTelegram ' . ($isCta ? 'cta ' : ''));
                        ?>
                        <li class="<?php echo $liClasses; ?>" id="<?php echo htmlspecialchars($id); ?>">
                            <a class="<?php echo $aClasses; ?>" href="<?php echo htmlspecialchars($href); ?>" title="<?php echo htmlspecialchars($title); ?>">
                                <?php echo htmlspecialchars($text); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="pane glassConvex" id="about">
            <?php echo $about; ?>
        </div>
```

Most panes are going to be like the second one.  You see it?  It might be tough to see because it's only 3 lines.  The one with the id `#about`.  Blink and you'll miss it!

1. `#links`
    - The `#links` pane is a special pane that provides a list of links, if it wasn't obvious enough.  It pulls it's data from `res/data/links.json`, and the code above cycles through that file and populates the `<div>` with links and separators.
    ```json
    [
        {
            "type": "link",
            "id": "butts",
            "href": "https://www.example.com",
            "text": "This is an example link.",
            "title": "This is a tool tip.  Do people even use this anymore?",
            "fullWidth": true,
            "cta": true
        },
        {
            "type": "separator"
        }
    ]
    ```
    The JSON object contains objects with everything you need, attributes and all, to generate an `<a>` element on the page.  
        - The `type` tells the script whether to parse it as a `link` or a `separator`.  If it's a `separator`, it just displays a line.  If it's a `link`...
        - We first check the `id`, which is just a unique identifier that populates the id attribut (normal HTML shenanigans).
        - Then we populate the `href` attribute, which is the URL.
        - The `text` is the label that the user will see in the link list.  In the above example, the button will say "This is an example link." instead of displaying the URL.
        - The `title` is a tool tip - the title attribute in HTML is generally what you see if you hover your mouse over a button.  It's not often seen in mobile sites, so do not put anything important on here, especially if your userbase are mostly zoomers who have their smartphones surgically attached to their hand and never touch grass.  Helpful information is good but be aware of your user base.
        - `fullWidth` and `cta` are boolean values. The former determines if the link takes up the full width of the list, or only half.  If it's half, links will appear side by side, 2 for each row.  The latter stands for "Call To Action" and will have the link appear distinct.  You can apply this to any link you want, but it's recommended to use this sparingly, i.e. if you have a main group, it's better to use this flag for that link only.
        - `separator` type objects are boring `<hr>` elements that take up width and aid in visual organization.  Nothing special happend with them, so we don't have additional data for them.  They're very lonely.  Use them so they'll be happy.
2. Any other Pane
    - As you can see above, simple, standard panes take up 3 lines, and if you wanted, could make them take up 1 line only to save space.  If you do, I am forced to assume you love pineapple on your pizza and should be avoided at all costs.  Not really, pineapple can be nice, and anyone who thinks it's "wrong" is wrong themselves.  Eat what you want to eat, my guy/girl/person/LLM/robot overlord/animal/catgirl/arch user, it's all good.  Wait a sec, what was I talking about again?
    - Content is read from a markdown file of the same name.  In the above example, we pull from `about.md`, which is specified at the top of the file, and the contents after being parsed are stored in the `$about` variable, and simply `echo`ed into the relevant div.
    - Initially, you can edit the file manually or edit it in `config.php`, however if you are not used to markdown, here's a few tips...
        - If you use Discord, formatting is generally handled through markdown.  There are ways of styling text without knowing it, but for the most part, if you construct posts frequently in discord instead of just do normal chatting, you may be familiar with markdown.
        - For reference, you can check out an awesome site like [commonmark](https://commonmark.org/help/)
        - Another awesome option is [StackEdit.io](https://stackedit.io/app#) as it provides a more user-friendly editor.  You can type up waht you want, use a GUI to format it, and then simply copy the text (the markdown, not the preview!) and paste it into the .md file (or in the editor).
        - You might hate my guts for making one use markdown without a fancy gui.  You might think I'm a terrible human being.  Don't worry, I find markdown frustrating to.  I also willingly and happily program in JavaScript.  So if you think I'm terrible, you're right.  Can't hate me more than I hate myself, so suck it. :3

#### Navigation Bar & Footer
Also known as the navbar, or `<nav>` because I'm trying to be semantic, this is where the user navigates to the various panes.  If you look at index.php in a browser and think to yourself "navigation bar that's translucent at the bottom of the page, with rounded edges?  Is this guy an Apple Sheep or something that's obsessed with liquid glass?" you'd be partially correct.  I'm not a sheep.  I like many of their UI designs, and since I'm developing this to be pleasant to use on mobile, I'm trying this design.  I also always liked unusual navigation bar placements, so having it at the bottom was a thing I've wanted to do.  It looks good, so for now, I'm keeping it.

And if you think I'm even dumber for placing the footer inside of `<nav>` instead of outside....  I ask that you refer to my above mention of me willingly developing in JavaScript.  What?  You don't know what I mean?  It's almost like I'm making an excuse to paste another code block below!  What?  I'd never do such-
```html
    <nav>
        <ul class="navBar glassConcave" id="navBar">
            <li><a class="navLink" href="#" data-pane="links">LINKS</a></li>
            <li><a class="navLink" href="#" data-pane="about">ABOUT</a></li>
            <li><a class="navLink" href="#" data-pane="rules">RULES</a></li>
            <li><a class="navLink" href="#" data-pane="faq">FAQ</a></li>
            <li><a class="navLink" href="#" data-pane="events">EVENTS</a></li>
            <li><a class="navLink" href="#" data-pane="donate">DONATE</a></li>
        </ul>
        <div class="footer">
            Powered by LawndingPage.  Background image by <span class="authorName"></span>.
        </div>
    </nav>
```
The navbar is currently hardcoded with each page.  Eventually I'll be altering it to dynamically generate, but for now, every time a new page is added, you'll want to update the navbar as well.  Furthermore, there's a few things of note.
- Each link contains a `data-pane` attribute.  It should match the `id` of the pane you are referencing.  Otherwise, nothing will happen when you click on a link, other than adding a hash (`#`) at the end of your URL, and will make the URL bar look ugly as a result.  So don't do that.  Or do, I don't care.
- If you don't use `data-pane`, you can simply just specify a URL.  Depending on your use case, you might want to also add `target="_blank"` inside of your `<a>` in that case.
- In the footer, you can see where the author name is added.  Not sure what I mean by author name?  Did you not read earlier?  Is TikTok and YouTube Shorts destroying your short term memory or something?  JESUS CHRIST SWEET SUMMER CHILD YOU'RE A DEVELOPER USE YOUR BRAI-  *ahem*  ...The `.authorName` span is the attribution to whatever background image is displaying.  As mentioned above, if no name is specified, it's populated with "anonymous".  How mysterious.

#### Beyond The Footer
After that is just some javascript stuff that makes the page do fun things.  By fun things, I mean it makes it interactive.  That's fun, right?  No?  Alright then.



### Admin Panel
Okay, so you've managed to get through the slog of `index.php` and now you want to know about the config side of things?

Well aren't you a good little nerd, trying to learn everything.  Well guess what?  I'm gonna be one of those devs that stops adding information making you HAVE to FAFO like a *real* nerd.  Ain't I a stinker?

OK, real talk for a moment.

Currently, I'm finalizing a few things for the config tool, including some type of session authentication, as well as getting a *proper* write-up made (that I can then ruin with dumb humor like above), in as much detail as I can manage.  The one thing I *hate* is not actually giving good documentation, and I *detest* assuming you know everything.  Yes, most people shouldn't be diving into this stuff without knowing the basics, but that's not how the world always works, and sometimes people learn by getting knee-deep in figurative crap, and figuring things out.  And that's me, sometimes, so I might as well give you the help I can.  That being said...

The core design of `config.php` is directly based on `index.php`.  Literally, I duplicated the file and started editing things.  The config page is responsive, as a result, and navigation is virtually identical, except for the addition of a "BG" link in the navbar that lets you add/remove background images.  That being said, everything you're editing is in the exact same location as it would appear on the main site.  I also included a tutorial (the help button in the top-right corner of the page, over the "Save All Changes" button), which should suffice until I finish typing up this part of the readme, or in the event I completely forget to update this readme.

I *swear* the former is most likely happening.  Totally.  Please believe me.  ....Okay don't believe me because I probably will forget.

Sarcasm aside, enjoy! :3

## Changelog
### v1.0.1
- Fixed a bug where certain file paths were not correctly being respected due to being hardcoded, causing the site to break, primarily with the admin panel.
- Fixed a bug where on mobile devices, when the navbar exceeded the view width, it would cause unexpected display issues, with parts of the UI clipping and being inaccessible.

### v1.0.0
- Initial Version

