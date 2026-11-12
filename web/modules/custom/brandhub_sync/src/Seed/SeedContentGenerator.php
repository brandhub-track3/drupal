<?php

namespace Drupal\brandhub_sync\Seed;

final class SeedContentGenerator {

  private const UUID_NAMESPACE = '0b1ad972-9f4f-4f66-9c7d-1324458f885e';


  private const PRODUCTS = [
    [
      'name' => 'Aurora Wireless Earbuds',
      'summary' => 'Comfortable wireless listening with clear calls and all-day battery life.',
      'body' => 'Aurora Wireless Earbuds are designed for commutes, focused work, and everyday calls. The compact charging case keeps them ready between listening sessions, while the touch controls make it easy to manage playback without reaching for a phone.',
      'tags' => ['Audio', 'Everyday essentials'],
    ],
    [
      'name' => 'HomeSync Smart Hub',
      'summary' => 'A simple control center for compatible lights, sensors, and routines.',
      'body' => 'HomeSync Smart Hub brings compatible connected-home devices into one place. Create practical routines for arriving home or winding down, and keep essential controls accessible to everyone in the household.',
      'tags' => ['Smart home', 'Setup'],
    ],
    [
      'name' => 'Lumen Desk Light',
      'summary' => 'Adjustable task lighting with comfortable brightness for reading and work.',
      'body' => 'Lumen Desk Light provides focused illumination for a home office, study area, or bedside table. Its adjustable arm helps direct light onto a book or workspace while keeping the base compact.',
      'tags' => ['Home office', 'Everyday essentials'],
    ],
    [
      'name' => 'TrailReady Power Bank',
      'summary' => 'Portable backup power for travel days and long commutes.',
      'body' => 'TrailReady Power Bank adds dependable charging capacity to a day bag. Check the indicator before leaving, use a compatible cable for your device, and recharge the bank after each trip so it is ready for the next one.',
      'tags' => ['Mobile', 'Travel'],
    ],
    [
      'name' => 'ClearCall USB Microphone',
      'summary' => 'Straightforward desktop audio for meetings, lessons, and recordings.',
      'body' => 'ClearCall USB Microphone helps voices sound focused during online meetings and remote lessons. Connect it directly to a compatible computer, position it close to the speaker, and run a short recording check before an important call.',
      'tags' => ['Audio', 'Home office'],
    ],
    [
      'name' => 'ViewPoint 27 Monitor',
      'summary' => 'A roomy display for multitasking, creative projects, and everyday computing.',
      'body' => 'ViewPoint 27 Monitor gives documents and creative applications more room to breathe. Adjust the screen height and viewing distance for comfort, then select the connection that best matches your computer.',
      'tags' => ['Home office', 'Setup'],
    ],
    [
      'name' => 'PocketBeam Mini Projector',
      'summary' => 'A compact display option for shared viewing at home or away.',
      'body' => 'PocketBeam Mini Projector makes it easy to set up a shared viewing space in a dim room. Place it on a stable surface, keep ventilation clear, and connect a compatible source before adjusting focus.',
      'tags' => ['Entertainment', 'Travel'],
    ],
    [
      'name' => 'BreezeQuiet Air Purifier',
      'summary' => 'A compact room purifier with simple controls and replaceable filtration.',
      'body' => 'BreezeQuiet Air Purifier is intended for everyday room use with straightforward controls. Leave space around the air inlets, check the filter according to the care guide, and choose a setting suited to the room.',
      'tags' => ['Home', 'Maintenance'],
    ],
    [
      'name' => 'FlexCharge Wireless Stand',
      'summary' => 'A convenient charging stand for compatible phones and earbuds.',
      'body' => 'FlexCharge Wireless Stand keeps a compatible phone upright while it charges, making notifications and video calls easier to view. Use the supplied power adapter guidance and center the device on the charging surface.',
      'tags' => ['Mobile', 'Everyday essentials'],
    ],
    [
      'name' => 'KeyWave Compact Keyboard',
      'summary' => 'A space-saving keyboard for desks, shared workspaces, and travel.',
      'body' => 'KeyWave Compact Keyboard frees up desk space without complicating everyday typing. Pair it using the device instructions, keep it clean with a dry cloth, and store it safely when working on the move.',
      'tags' => ['Home office', 'Accessibility'],
    ],
  ];

  /**
   * Article editorial data.
   *
   * @var list<array{title: string, summary: string, topic: string, product: int, tags: list<string>, body: string}>
   */
  private const ARTICLES = [
    [
      'title' => 'Choose Earbuds That Stay Comfortable Through the Day',
      'summary' => 'A practical fit check can make everyday listening more comfortable.',
      'topic' => 'Buying guides',
      'product' => 0,
      'tags' => ['Audio', 'Buying guide'],
      'body' => 'Try each ear tip size and choose the one that seals gently ' .
      'without pressure. Wear the earbuds for a short listening session ' .
      'before a long commute, and keep the case nearby so you can store ' .
      'them safely between uses.',
    ],
    [
      'title' => 'Plan a Smart Home Setup One Room at a Time',
      'summary' => 'Starting with a small routine makes a connected home easier to manage.',
      'topic' => 'Setup and connectivity',
      'product' => 1,
      'tags' => ['Smart home', 'Setup'],
      'body' => 'Begin with one room and a clear goal, such as switching off a ' .
      'lamp at bedtime. Confirm that each device is compatible with the ' .
      'hub, use a consistent naming scheme, and add more devices only ' .
      'after the first routine works reliably.',
    ],
    [
      'title' => 'Set Up a Desk Light for Reading and Video Calls',
      'summary' => 'A few small adjustments can reduce glare and improve task lighting.',
      'topic' => 'Home office',
      'product' => 2,
      'tags' => ['Home office', 'Setup'],
      'body' => 'Place the light so it illuminates your work without reflecting ' .
      'directly into the screen. Adjust the arm to keep the light source ' .
      'out of your line of sight, then test the arrangement during both ' .
      'reading and a video call.',
    ],
    [
      'title' => 'Keep a Power Bank Ready for a Travel Day',
      'summary' => 'Simple charging habits help portable power stay useful when you need it.',
      'topic' => 'Care and maintenance',
      'product' => 3,
      'tags' => ['Mobile', 'Travel'],
      'body' => 'Charge the power bank before departure and check its indicator ' .
      'rather than relying on memory. Pack a compatible cable, avoid ' .
      'leaving the unit in excessive heat, and recharge it after the ' .
      'trip so it is ready for daily use.',
    ],
    [
      'title' => 'Improve Voice Clarity in Online Meetings',
      'summary' => 'Microphone position and a quick sound check often matter more than extra settings.',
      'topic' => 'Home office',
      'product' => 4,
      'tags' => ['Audio', 'Home office'],
      'body' => 'Place the microphone a comfortable distance from your mouth and ' .
      'away from keyboard noise. Record a short sample, listen for room ' .
      'echo, and use the meeting application meter to confirm that your ' .
      'voice is not clipping.',
    ],
    [
      'title' => 'Arrange a Monitor for a More Comfortable Workspace',
      'summary' => 'A comfortable screen position supports longer stretches of focused work.',
      'topic' => 'Accessibility',
      'product' => 5,
      'tags' => ['Home office', 'Accessibility'],
      'body' => 'Center the monitor in front of your chair and adjust its height ' .
      'so you can look ahead without leaning. Keep text at a readable ' .
      'scale, reduce reflections, and take regular breaks to change ' .
      'posture.',
    ],
    [
      'title' => 'Prepare a Small Room for Projector Viewing',
      'summary' => 'Room lighting, distance, and a stable surface make setup more predictable.',
      'topic' => 'Setup and connectivity',
      'product' => 6,
      'tags' => ['Entertainment', 'Setup'],
      'body' => 'Choose a stable surface and a dim area before connecting the ' .
      'projector. Leave ventilation unobstructed, route cables where ' .
      'people will not trip, and adjust focus after the unit is in its ' .
      'final position.',
    ],
    [
      'title' => 'Make an Air Purifier Easier to Maintain',
      'summary' => 'A regular filter check helps an air purifier work as intended.',
      'topic' => 'Care and maintenance',
      'product' => 7,
      'tags' => ['Home', 'Maintenance'],
      'body' => 'Keep the purifier away from curtains and furniture that block its ' .
      'air path. Follow the filter inspection schedule in the product ' .
      'guide, use the recommended replacement, and wipe the exterior ' .
      'with a dry or lightly damp cloth.',
    ],
    [
      'title' => 'Find the Best Position for a Wireless Charging Stand',
      'summary' => 'A clear, stable surface helps make daily charging convenient.',
      'topic' => 'Buying guides',
      'product' => 8,
      'tags' => ['Mobile', 'Buying guide'],
      'body' => 'Place the stand on a level surface near a suitable power outlet. ' .
      'Remove accessories that prevent alignment, center the compatible ' .
      'device, and check its charging indicator before leaving it ' .
      'unattended.',
    ],
    [
      'title' => 'Keep a Compact Keyboard Clean Between Work Sessions',
      'summary' => 'Gentle routine care can keep a shared keyboard pleasant to use.',
      'topic' => 'Care and maintenance',
      'product' => 9,
      'tags' => ['Home office', 'Maintenance'],
      'body' => 'Disconnect or switch off the keyboard before cleaning. Turn it ' .
      'over gently to release loose debris and wipe the keys with a soft ' .
      'cloth; avoid spraying liquid directly onto the device.',
    ],
    [
      'title' => 'Compare Everyday Audio Features Before You Buy',
      'summary' => 'Focus on fit, controls, and call quality rather than a long feature list.',
      'topic' => 'Buying guides',
      'product' => 0,
      'tags' => ['Audio', 'Buying guide'],
      'body' => 'Consider where you listen most often and how long a typical ' .
      'session lasts. Check comfort, control placement, charging ' .
      'convenience, and call performance against those routines before ' .
      'comparing secondary features.',
    ],
    [
      'title' => 'Use Device Names That Make Smart Routines Clear',
      'summary' => 'Consistent names make voice and app controls easier for everyone.',
      'topic' => 'Setup and connectivity',
      'product' => 1,
      'tags' => ['Smart home', 'Accessibility'],
      'body' => 'Name devices after their location and purpose, such as a room and ' .
      'light type. Avoid names that sound alike, then test each routine ' .
      'with the people who will use it so controls remain easy to ' .
      'understand.',
    ],
    [
      'title' => 'Reduce Screen Glare in a Home Office',
      'summary' => 'Small changes to screen angle and room lighting can improve visibility.',
      'topic' => 'Home office',
      'product' => 5,
      'tags' => ['Home office', 'Accessibility'],
      'body' => 'Position the monitor perpendicular to bright windows where ' .
      'possible. Adjust blinds and screen angle before increasing ' .
      'brightness, and keep the display clean using the care ' .
      'instructions for its panel.',
    ],
    [
      'title' => 'Pack Charging Accessories Without the Cable Tangle',
      'summary' => 'A simple packing routine keeps portable charging gear easy to find.',
      'topic' => 'Care and maintenance',
      'product' => 3,
      'tags' => ['Mobile', 'Travel'],
      'body' => 'Use a small pouch for the power bank and its compatible cable. ' .
      'Check connector condition before travel, keep the battery away ' .
      'from sharp objects, and avoid packing it where it can be crushed.',
    ],
    [
      'title' => 'Choose a Microphone Position for a Shared Workspace',
      'summary' => 'A thoughtful position can help keep your voice clear without disturbing others.',
      'topic' => 'Home office',
      'product' => 4,
      'tags' => ['Audio', 'Home office'],
      'body' => 'Aim the microphone toward your speaking position and away from ' .
      'nearby conversation. Use headphones to prevent speaker feedback, ' .
      'and check privacy settings before joining a call in a shared room.',
    ],
    [
      'title' => 'Create a Simple Evening Smart Home Routine',
      'summary' => 'A short, easy-to-review routine is a good starting point for automation.',
      'topic' => 'Setup and connectivity',
      'product' => 1,
      'tags' => ['Smart home', 'Setup'],
      'body' => 'Start with one or two actions, such as turning off a selected ' .
      'light. Review every connected device included in the routine, ' .
      'keep a manual control available, and update the routine when ' .
      'household needs change.',
    ],
    [
      'title' => 'Set Up a Projector Without Blocking Airflow',
      'summary' => 'Good placement protects ventilation and keeps cables manageable.',
      'topic' => 'Care and maintenance',
      'product' => 6,
      'tags' => ['Entertainment', 'Maintenance'],
      'body' => 'Read the placement guidance before choosing a shelf or table. ' .
      'Leave the specified clearance around vents, keep the power cable ' .
      'visible and secure, and let the projector cool as directed before ' .
      'storing it.',
    ],
    [
      'title' => 'Check a Wireless Charger and Phone for Alignment',
      'summary' => 'A quick indicator check can catch a charging position that is off center.',
      'topic' => 'Troubleshooting',
      'product' => 8,
      'tags' => ['Mobile', 'Setup'],
      'body' => 'Set the phone on the center of the stand and look for the device ' .
      'charging indicator. If charging does not begin, reposition the ' .
      'phone and check whether a case or accessory is interfering with ' .
      'the charging surface.',
    ],
    [
      'title' => 'Make Desk Accessories Work for a Small Space',
      'summary' => 'Choose compact accessories that support the way you actually use the desk.',
      'topic' => 'Home office',
      'product' => 9,
      'tags' => ['Home office', 'Buying guide'],
      'body' => 'Measure the available desk area before choosing accessories. Keep ' .
      'frequently used items within reach, leave room for writing or a ' .
      'pointing device, and route cables so they do not crowd the ' .
      'workspace.',
    ],
    [
      'title' => 'Build a Practical Device Care Checklist',
      'summary' => 'A short checklist makes routine maintenance easier to remember.',
      'topic' => 'Care and maintenance',
      'product' => 7,
      'tags' => ['Maintenance', 'Everyday essentials'],
      'body' => 'List the care tasks recommended by each product guide and set ' .
      'reminders at reasonable intervals. Include filter or cable checks ' .
      'where relevant, and stop using a device if it appears damaged ' .
      'until it has been inspected safely.',
    ],
  ];

  /**
   * Frequently asked questions and answers.
   *
   * @var list<array{question: string, answer: string, topic: string, product: int, tag: string}>
   */
  private const FAQS = [
    [
      'question' => 'How do I get a comfortable fit with Aurora Wireless Earbuds?',
      'answer' => 'Try the supplied ear tips one size at a time and choose a fit ' .
      'that feels secure without pressure. If comfort changes during ' .
      'use, remove the earbuds and take a break rather than forcing a ' .
      'tight fit.',
      'topic' => 'Audio',
      'product' => 0,
      'tag' => 'Audio',
    ],
    [
      'question' => 'What should I check before adding a device to HomeSync Smart Hub?',
      'answer' => 'Check that the device is listed as compatible with the hub and ' .
      'that both are using the setup requirements in their guides. Keep ' .
      'the hub nearby during pairing and give each device a clear ' .
      'room-based name.',
      'topic' => 'Setup and connectivity',
      'product' => 1,
      'tag' => 'Smart home',
    ],
    [
      'question' => 'Where should I place the Lumen Desk Light?',
      'answer' => 'Place it on a stable surface and direct the beam onto your work ' .
      'without shining into your eyes or reflecting from a screen. Keep ' .
      'the lamp clear of objects that could obstruct its movement or ' .
      'ventilation.',
      'topic' => 'Home office',
      'product' => 2,
      'tag' => 'Setup',
    ],
    [
      'question' => 'How can I tell whether TrailReady Power Bank is ready for a trip?',
      'answer' => 'Check the battery indicator before leaving and recharge the unit ' .
      'if its level is low. Bring a compatible cable and follow the ' .
      'product guide for storage and temperature recommendations.',
      'topic' => 'Mobile',
      'product' => 3,
      'tag' => 'Travel',
    ],
    [
      'question' => 'Why does my ClearCall USB Microphone sound quiet?',
      'answer' => 'Confirm that the microphone is selected as the input device, move ' .
      'it closer to your speaking position, and check the application ' .
      'input level. Make a short test recording before changing advanced ' .
      'audio settings.',
      'topic' => 'Troubleshooting',
      'product' => 4,
      'tag' => 'Audio',
    ],
    [
      'question' => 'How do I position ViewPoint 27 Monitor for daily work?',
      'answer' => 'Center the display in front of your usual sitting position and ' .
      'adjust its height and angle for a relaxed view. Reduce ' .
      'reflections and use a readable display scale rather than leaning ' .
      'closer to small text.',
      'topic' => 'Accessibility',
      'product' => 5,
      'tag' => 'Home office',
    ],
    [
      'question' => 'Can PocketBeam Mini Projector be used in a bright room?',
      'answer' => 'A dimmer room generally makes projected content easier to see. ' .
      'Check the projector guide for suitable room conditions, keep its ' .
      'vents clear, and adjust focus after placing it on a stable ' .
      'surface.',
      'topic' => 'Entertainment',
      'product' => 6,
      'tag' => 'Setup',
    ],
    [
      'question' => 'How often should I check the BreezeQuiet Air Purifier filter?',
      'answer' => 'Follow the inspection and replacement interval in the product ' .
      'guide, since conditions and usage can vary. Keep the air inlets ' .
      'clear and use only a replacement filter recommended for the ' .
      'purifier.',
      'topic' => 'Care and maintenance',
      'product' => 7,
      'tag' => 'Maintenance',
    ],
    [
      'question' => 'Why is FlexCharge Wireless Stand not charging my phone?',
      'answer' => 'Center a compatible phone on the stand, check the power ' .
      'connection, and look for the phone charging indicator. Remove ' .
      'accessories that may prevent alignment and consult both device ' .
      'guides if charging still does not start.',
      'topic' => 'Troubleshooting',
      'product' => 8,
      'tag' => 'Mobile',
    ],
    [
      'question' => 'What is the safest way to clean KeyWave Compact Keyboard?',
      'answer' => 'Switch off or disconnect the keyboard first. Use a soft, dry ' .
      'cloth and gently remove loose debris; do not spray liquid ' .
      'directly onto keys or use cleaning products that are not approved ' .
      'in the care guide.',
      'topic' => 'Care and maintenance',
      'product' => 9,
      'tag' => 'Maintenance',
    ],
    [
      'question' => 'How can I reduce interruptions from smart home routines?',
      'answer' => 'Keep routines focused on actions that are useful at a particular ' .
      'time or place. Review the devices and schedule, make sure manual ' .
      'controls remain available, and disable a routine if it no longer ' .
      'fits your household.',
      'topic' => 'Setup and connectivity',
      'product' => 1,
      'tag' => 'Smart home',
    ],
    [
      'question' => 'Should I leave a power bank in a hot car?',
      'answer' => 'No. Avoid storing portable batteries in excessive heat or direct ' .
      'sunlight. Follow the manufacturer storage guidance and inspect a ' .
      'device that has been exposed to harsh conditions before using it ' .
      'again.',
      'topic' => 'Care and maintenance',
      'product' => 3,
      'tag' => 'Mobile',
    ],
    [
      'question' => 'How do I prevent monitor reflections during a video call?',
      'answer' => 'Adjust the display angle and nearby lighting, and avoid placing a ' .
      'bright window directly behind or in front of the screen. A small ' .
      'change in desk position can improve visibility without turning ' .
      'brightness up too far.',
      'topic' => 'Home office',
      'product' => 5,
      'tag' => 'Accessibility',
    ],
    [
      'question' => 'Can I use a USB microphone in a shared room?',
      'answer' => 'Yes, but choose a position that captures your voice without ' .
      'pointing toward other conversations. Use headphones where ' .
      'practical and check that the meeting application is using the ' .
      'intended microphone.',
      'topic' => 'Home office',
      'product' => 4,
      'tag' => 'Audio',
    ],
    [
      'question' => 'What should I do if a device or cable looks damaged?',
      'answer' => 'Stop using the damaged item, disconnect it only if it is safe to ' .
      'do so, and follow the manufacturer support guidance. Do not ' .
      'attempt repairs that are not described as user-serviceable.',
      'topic' => 'Care and maintenance',
      'product' => 7,
      'tag' => 'Everyday essentials',
    ],
  ];

  /**
   * Taxonomy labels used by the generated content.
   *
   * @return array<string, list<string>>
   *   Vocabulary machine names mapped to term labels.
   */
  public function taxonomyTerms(): array {
    $terms = [
      'product' => array_column(self::PRODUCTS, 'name'),
      'topic' => [
        'Buying guides',
        'Setup and connectivity',
        'Home office',
        'Care and maintenance',
        'Accessibility',
        'Troubleshooting',
        'Entertainment',
        'Audio',
        'Mobile',
      ],
      'tags' => [
        'Audio',
        'Everyday essentials',
        'Smart home',
        'Setup',
        'Home office',
        'Mobile',
        'Travel',
        'Entertainment',
        'Home',
        'Maintenance',
        'Accessibility',
        'Buying guide',
      ],
    ];

    return $terms;
  }

  /**
   * Generates deterministic node data for the requested counts.
   *
   * @return list<array<string, mixed>>
   *   Seed items with bundle, UUID, title, and field values.
   */
  public function generate(int $products, int $articles, int $faqs): array {
    $items = [];
    foreach (array_slice(self::PRODUCTS, 0, $products) as $index => $product) {
      $items[] = [
        'bundle' => 'product',
        'uuid' => $this->uuid('node:product:' . ($index + 1)),
        'title' => $product['name'],
        'fields' => [
          'field_summary' => [['value' => $product['summary']]],
          'field_body' => [[
            'value' => '<p>' . $product['body'] . '</p>',
            'format' => 'basic_html',
          ],
          ],
          'field_product' => [['term' => $product['name']]],
          'field_published_date' => [['value' => 1736942400 + ($index * 86400)]],
          'field_tag' => array_map(
            static fn(string $tag): array => ['term' => $tag],
            $product['tags'],
          ),
        ],
      ];
    }

    foreach (array_slice(self::ARTICLES, 0, $articles) as $index => $article) {
      $product = self::PRODUCTS[$article['product']];
      $items[] = [
        'bundle' => 'article',
        'uuid' => $this->uuid('node:article:' . ($index + 1)),
        'title' => $article['title'],
        'fields' => [
          'body' => [[
            'value' => '<p>' . $article['body'] . '</p>',
            'summary' => $article['summary'],
            'format' => 'basic_html',
          ],
          ],
          'field_a_summary' => [['value' => $article['summary']]],
          'field_a_product' => [['term' => $product['name']]],
          'field_a_topic' => [['term' => $article['topic']]],
          'field_tags' => array_map(
            static fn(string $tag): array => ['term' => $tag],
            $article['tags'],
          ),
        ],
      ];
    }

    foreach (array_slice(self::FAQS, 0, $faqs) as $index => $faq) {
      $product = self::PRODUCTS[$faq['product']];
      $items[] = [
        'bundle' => 'faq',
        'uuid' => $this->uuid('node:faq:' . ($index + 1)),
        'title' => $faq['question'],
        'fields' => [
          'field_answer' => [[
            'value' => '<p>' . $faq['answer'] . '</p>',
            'format' => 'basic_html',
          ],
          ],
          'field_faq_product' => [['term' => $product['name']]],
          'field_faq_topic' => [['term' => $faq['topic']]],
          'field_faq_tags' => [['term' => $faq['tag']]],
        ],
      ];
    }

    return $items;
  }

  /**
   * Creates a deterministic UUID for one owned seed record.
   */
  public function uuid(string $seed_key): string {
    $namespace = hex2bin(str_replace('-', '', self::UUID_NAMESPACE));
    if ($namespace === FALSE) {
      throw new \LogicException('The seed UUID namespace is invalid.');
    }

    $hash = sha1($namespace . 'brandhub_sync:phase18:' . $seed_key, TRUE);
    $hash[6] = chr((ord($hash[6]) & 0x0f) | 0x50);
    $hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80);
    $hex = bin2hex(substr($hash, 0, 16));

    return sprintf(
      '%s-%s-%s-%s-%s',
      substr($hex, 0, 8),
      substr($hex, 8, 4),
      substr($hex, 12, 4),
      substr($hex, 16, 4),
      substr($hex, 20, 12),
    );
  }

}
