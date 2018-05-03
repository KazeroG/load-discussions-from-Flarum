<?php

/**
 * Vanilla PHP helper to load discussions from Flarum API
 * @author Clark Winkelmann
 * @license MIT (c) Clark Winkelmann 2018
 */
class FlarumDiscussionStream
{
    /**
     * Flarum url without ending slash (same as the url in Flarum config.php)
     * @var string
     */
    protected $flarumUrl = 'https://manslandlife.tk/forum/v2';

    protected $parsedData;

    protected $limit = 5;

    protected $tag = null;

    protected $include = [
        'startUser',
        'startPost',
    ];

    public function tag($tag)
    {
        $this->tag = $tag;

        return $this;
    }

    public function limit($limit)
    {
        $this->limit = intval($limit);

        return $this;
    }

    public function replaceInclude($include)
    {
        $this->include = $include;

        return $this;
    }

    public function fetch()
    {
        $url = "{$this->flarumUrl}/api/discussions?include=" . implode(',', $this->include) . "&page[limit]={$this->limit}" . ($this->tag ? '&filter[q]=tag:' . urlencode($this->tag) : '');

        $this->queryAndParse($url);

        return $this;
    }

    protected function queryAndParse($url)
    {
        $response = file_get_contents($url);

        if ($response === false) {
            throw new \Exception('Could not fetch the posts');
        }

        $document = json_decode($response);

        if ($document === null) {
            throw new \Exception('Could not decode the posts');
        }

        if (!property_exists($document, 'data') || !is_array($document->data)) {
            throw new \Exception('Invalid or missing data key in the posts response');
        }

        $this->parsedData = [
            'data' => [],
            'included' => [],
        ];

        foreach ($document->data as $data) {
            if (
                property_exists($data, 'id') &&
                property_exists($data, 'type') &&
                $data->type === 'discussions' &&
                property_exists($data, 'attributes') &&
                is_object($data->attributes) &&
                property_exists($data->attributes, 'slug')
            ) {
                // Create the url attribute for discussions as it is not part of the payload
                $data->attributes->url = "{$this->flarumUrl}/d/{$data->id}-{$data->attributes->slug}";
            }

            $this->parsedData['data'][] = $data;
        }

        if (property_exists($document, 'included')) {
            if (!is_array($document->included)) {
                throw new \Exception('Invalid or missing data key in the posts response');
            }

            foreach ($document->included as $included) {
                if (!property_exists($included, 'type') || !property_exists($included, 'id')) {
                    throw new \Exception('Missing type of id in included payload');
                }

                if (!array_key_exists($included->type, $this->parsedData['included'])) {
                    $this->parsedData['included'][$included->type] = [];
                }

                $this->parsedData['included'][$included->type][$included->id] = $included;
            }
        }
    }

    /**
     * @return \stdClass[]
     * @throws \Exception
     */
    public function discussions()
    {
        if (!$this->parsedData) {
            throw new \Exception('Data not ready. Use fetch first');
        }

        return $this->parsedData['data'];
    }

    /**
     * @param \stdClass $data
     * @return \stdClass
     * @throws Exception
     */
    public function relationship($data)
    {
        // If we pass the whole relationships object instead of one of the relationship element
        if (property_exists($data, 'data')) {
            $data = $data->data;
        }

        if (!property_exists($data, 'type') || !property_exists($data, 'id')) {
            throw new \Exception('Missing type or id in relatinship query');
        }

        if (!array_key_exists($data->type, $this->parsedData['included']) || !array_key_exists($data->id, $this->parsedData['included'][$data->type])) {
            throw new \Exception("No resource matches type {$data->type} and id {$data->id}");
        }

        return $this->parsedData['included'][$data->type][$data->id];
    }
}

/**
 * Simple function to create a plain text excerpt from html code
 * @param string $text Input text
 * @param int $length Maximum length of the result
 * @param string $ending Text added in case of elipsis
 * @return string Resulting excerpt text
 */
function excerpt($text, $length = 200, $ending = '...')
{
    $noHtml = strip_tags($text);

    if (strlen($noHtml) <= $length) {
        return $noHtml;
    }

    return substr($noHtml, 0, $length) . $ending;
}

// Let's fetch some discussions
$discussions = (new FlarumDiscussionStream())->tag('dev')->fetch();

?>

<?php foreach ($discussions->discussions() as $discussion): ?>

    <article>
        <h1><a href="<?= htmlspecialchars($discussion->attributes->url) ?>">
                <?= htmlspecialchars($discussion->attributes->title) ?>
            </a></h1>
        <p>
            Par <?= htmlspecialchars($discussions->relationship($discussion->relationships->startUser)->attributes->username) ?>
            Sur <?= DateTime::createFromFormat(DATE_ATOM, $discussion->attributes->startTime)->format('Y-m-d H:i') ?>
        </p>
        <p>
            <?= htmlspecialchars(excerpt($discussions->relationship($discussion->relationships->startPost)->attributes->contentHtml)) ?>
        </p>
    </article>

<?php endforeach; ?>
