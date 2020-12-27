<?php
class ReutersBridge extends BridgeAbstract
{
	const MAINTAINER = 'hollowleviathan, spraynard, csisoap';
	const NAME = 'Reuters Bridge';
	const URI = 'https://reuters.com/';
	const CACHE_TIMEOUT = 1800; // 30min
	const DESCRIPTION = 'Returns news from Reuters';
	private $feedName = self::NAME;

	const ALLOWED_WIREITEM_TYPES = array(
		'story',
		'headlines'
	);

	const ALLOWED_TEMPLATE_TYPES = array(
		'story'
	);

	const PARAMETERS = array(
		array(
			'feed' => array(
				'name' => 'News Feed',
				'type' => 'list',
				'exampleValue' => 'World',
				'title' => 'Reuters feed. World, US, Tech...',
				'values' => array(
					'Tech' => 'tech',
					'Wire' => 'wire',
					'Health' => 'health',
					'Business' => 'business',
					'World' => 'world',
					'Politics' => 'politics',
					'Science' => 'science',
					'Lifestyle' => 'life',
					'Energy' => 'energy',
					'Aerospace and Defence' => 'aerospace',
					'Special Reports' => 'special-reports',
					'China' => 'china',
					'Top News' => 'home/topnews',
					'Markets' => 'markets',
					'Sports' => 'sports',
					'USA News' => 'us',
				),
			),
		),
	);

	private function getJson($feed_uri)
	{
		$uri = "https://wireapi.reuters.com/v8$feed_uri";
		$returned_data = getContents($uri);
		return json_decode($returned_data, true);
	}

	public function getName()
	{
		return $this->feedName;
	}

	private function processData($data)
	{
		/**
		 * Gets a list of wire items which are groups of templates
		 */
		$reuters_allowed_wireitems = array_filter(
			$data, function ($wireitem) {
				return in_array(
					$wireitem['wireitem_type'],
					self::ALLOWED_WIREITEM_TYPES
				);
			}
		);

		/*
		* Gets a list of "Templates", which is data containing a story
		*/
		$reuters_wireitem_templates = array_reduce(
			$reuters_allowed_wireitems,
			function (array $carry, array $wireitem) {
				$wireitem_templates = $wireitem['templates'];
				return array_merge(
					$carry,
					array_filter(
						$wireitem_templates, function (
							array $template_data
						) {
							return in_array(
								$template_data['type'],
								self::ALLOWED_TEMPLATE_TYPES
							);
						}
					)
				);
			},
			array()
		);

		return $reuters_wireitem_templates;
	}

	private function getArticle($feed_uri)
	{
		// This will make another request to API to get full detail of article and author's name.
		$process_data = $this->getJson($feed_uri);
		$reuters_wireitems = $process_data['wireitems'];
		$processedData = $this->processData($reuters_wireitems);

		$first = reset($processedData);
		$article_content = $first['story']['body_items'];
		$authorlist = $first['story']['authors'];
		$category = $first['story']['channel']['name'];
		$image_list = $first['story']['images'];
		$img_placeholder = '';

		foreach($image_list as $image) { // Add more image to article.
			$image_url = $image['url'];
			$image_caption = $image['caption'];
			$img = "<img src=\"$image_url\">";
			$img_caption = "<figcaption style=\"text-align: center;\"><i>$image_caption</i></figcaption>";
			$figure = "<figure>$img \t $img_caption</figure>";
			$img_placeholder = $img_placeholder . $figure;
		}

		$author = '';
		$counter = 0;
		foreach ($authorlist as $data) {
			//Formatting author's name.
			$counter++;
			$name = $data['name'];
			if ($counter == count($authorlist)) {
				$author = $author . $name;
			} else {
				$author = $author . "$name, ";
			}
		}

		$description = '';
		foreach ($article_content as $content) {
			if ($content['type'] == 'inline_items') {
				//Fix issue with some content included brand name or company name.
				$item_list = $content['items'];
				$description = $description . '<p>';
				foreach ($item_list as $item) {
					if($item['type'] == 'text') {
						$description = $description . $item['content'];
					} else {
						$description = $description . $item['symbol'];
					}
				}
				$description = $description . '</p>';
			} else {
				if(isset($content['content'])) {
					$data = $content['content'];
					if (strtoupper($data) == $data
						|| $content['type'] == 'heading'
					) {
						//Add heading for any part of content served as header.
						$description = $description . "<h3>$data</h3>";
					} else {
						if (strpos($data, '.png') !== false
						|| strpos($data, '.jpg') !== false
						|| strpos($data, '.PNG') !== false
						|| strpos($data, '.JPG') !== false
						) {
							$description = $description . "<img src=\"$data\">";
						} else {
							$description = $description . "<p>$data</p>";
						}
					}
				}
			}
		}

		$content_detail = array(
			'content' => $description,
			'author' => $author,
			'category' => $category,
			'images' => $img_placeholder,
		);
		return $content_detail;
	}

	public function collectData()
	{
		$feed = $this->getInput('feed');
		$feed_uri = "/feed/rapp/us/tabbar/feeds/$feed";
		$data = $this->getJson($feed_uri);
		$reuters_wireitems = $data['wireitems'];
		$this->feedName = $data['wire_name'] . ' | Reuters';
		$processedData = $this->processData($reuters_wireitems);

		// Merge all articles from Editor's Highlight section into existing array of templates.
		$top_section = reset($reuters_wireitems);
		if ($top_section['wireitem_type'] == 'headlines') {
			$top_articles = $top_section['templates'][1]['headlines'];
			$processedData = array_merge($top_articles, $processedData);
		}

		foreach ($processedData as $story) {
			$item['uid'] = $story['story']['usn'];
			$article_uri = $story['template_action']['api_path'];
			$content_detail = $this->getArticle($article_uri);
			$description = $content_detail['content'];
			$author = $content_detail['author'];
			$images = $content_detail['images'];
			$item['categories'] = array($content_detail['category']);
			$item['author'] = $author;
			if (!(bool) $description) {
				$description = $story['story']['lede']; // Just in case the content doesn't have anything.
			} else {
				$item['content'] = "$description  $images";
			}

			$item['title'] = $story['story']['hed'];
			$item['timestamp'] = $story['story']['updated_at'];
			$item['uri'] = $story['template_action']['url'];
			$this->items[] = $item;
		}
	}
}
