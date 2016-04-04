<?php
/**
 * Created by PhpStorm.
 * User: bona
 * Date: 31/03/16
 * Time: 21:24
 */

namespace Drupal\elastique\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defines a generic controller to render a single entity.
 */
class RestController implements ContainerInjectionInterface {

  /**
   * The entity manager
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * A list of supported formats.
   *
   * @var array
   */
  protected $supportedFormats = [
    'xml'  => 'application/xml',
    'json' => 'application/json',
    'csv'  => 'application/csv',
    'html' => 'text/html',
  ];

  /**
   * Error message.
   *
   * @var array
   */
  protected $notFound = [
    'error' => [
      'message' => 'Not Found',
      'code'    => 404,
    ]
  ];

  /**
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   *
   * @param \Symfony\Component\HttpFoundation\Request $current_request
   *   The current request.
   */
  public function __construct(EntityManagerInterface $entity_manager, Request $current_request) {
    $this->currentRequest = $current_request;
    $this->entityManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('request_stack')->getCurrentRequest()
    );
  }

  /**
   * Serve a list of publishers in the sytem.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function publishersList($_format) {
    $list['publishers'] = [];

    $publishers = $this->getNodeStorage()->loadMultiple();

    $i =  0;
    foreach ($publishers as $nid => $node) {
      if ($node->getType() == 'publisher') {
        $list['publishers'][$i]['id'] = $node->id();
        $list['publishers'][$i]['name'] = $node->getTitle();
        $i++;
      }
    }
    return $this->formatResponse($list, $_format);
  }

  /**
   * The node storage.
   *
   * @return Drupal\node\NodeStorage
   */
  protected function getNodeStorage() {
    return $this->entityManager->getStorage('node');
  }

  /**
   * @param array $data
   *   A list of data to serve.
   *
   * @param string $default_format
   *   The default format the router will fall to incase not specified in the url.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function formatResponse($data, $default_format) {
    $format = $this->currentRequest->query->get('_format', $default_format);
    $this->response_string =& $response;

    // It is a supported format, so just run its formatting method
    if (array_key_exists($format, $this->supportedFormats)) {
      $response = $this->$format($data);
    }
    else {
      // For unsuprted formats use the default route format.
      $error['error']['message'] = 'Unsupported Media Type';
      $error['error']['code'] = 415;
      $error['error']['allowed'] = implode(',', array_keys($this->supportedFormats));
      $response = $this->$default_format($error);
    }

    return $response;
  }

  /**
   * Serve a publisher with a specified ID.
   *
   * @param int $id
   *   The integer that uniquely indetifies a publisher in the system.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function publishersId($id, $_format) {

    $this->getBookAuthorData();

    $node = $this->getNodeStorage()->load($id);

    if ($node->bundle() == 'publisher') {
      $data['id'] = $node->id();
      $data['name'] = $node->getTitle();
    }
    else {
      $data = $this->notFound;
    }

    return $this->formatResponse($data, $_format);
  }

  /**
   * Get books and authors in the system.
   *
   * @return array
   *   A list of books and authors.
   */
  protected function getBookAuthorData() {
    $userStorage = $this->entityManager->getStorage('user');
    $book_list = [];
    $authors = [];

    $books = $this->getNodeStorage()->loadMultiple();

    foreach ($books as $nid => $node) {
      if ($node->getType() == 'book') {
        $book_list[$nid] = $this->getBook($node);

        $uid = $node->get('field_author')->target_id;
        $author = $userStorage->load($uid);

        if ($author) {
          $authors[$uid] = [
            'id'   => $uid,
            'name' => $author->getUsername()
          ];
        }
      }
    }

    return [$book_list, $authors];
  }

  /**
   * Get a book.
   *
   * @param $node
   *   The book.
   *
   * @return array
   *   A list of book data parameters.
   */
  protected function getBook($node) {
    $userStorage = $this->entityManager->getStorage('user');

    $pub_id = $node->get('field_publisher')->target_id;
    $publisher = $this->getNodeStorage()->load($pub_id);

    $uid = $node->get('field_author')->target_id;
    $author = $userStorage->load($uid);

    $data = [
      'id'          => $node->id(),
      'title'       => $node->getTitle(),
      'highlighted' => $node->get('field_highlighted')->value ? TRUE : FALSE,
      'publisher'   => [
        'id'   => $pub_id,
        'name' => $publisher->getTitle(),
      ],
      'author'      => [
        'id'   => $uid,
        'name' => $author->getUsername(),
      ],
    ];

    return $data;
  }

  /**
   * Serve a list of authors in the sytem.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function authorList($_format) {
    list(, $list) = $this->getBookAuthorData();
    $data['authors'] = array_values($list);
    return $this->formatResponse($data, $_format);
  }

  /**
   * Serve an author with a specified ID.
   *
   * @param int $id
   *   The integer that uniquely indetifies an author in the system.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function authorId($id, $_format) {
    list(, $list) = $this->getBookAuthorData();

    if (array_key_exists($id, $list)) {
      $data = $list[$id];
    }
    else {
      $data = $this->notFound;
    }

    return $this->formatResponse($data, $_format);
  }

  /**
   * Serve a book with a specified ID.
   *
   * @param int $id
   *   The integer that uniquely indetifies a book in the system.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function bookId($id, $_format) {
    $node = $this->getNodeStorage()->load($id);

    if ($node->bundle() == 'book') {
      $data = $this->getBook($node);
    }
    else {
      $data = $this->notFound;
    }

    return $this->formatResponse($data, $_format);
  }

  /**
   * Server highlighted books.
   *
   * Callback for elastique.books_highlighted router.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A response.
   */
  public function booksHighlighted($_format) {
    $highlighted = [];
    list($books) = $this->getBookAuthorData();

    foreach ($books as $book) {
      if (!empty($book['highlighted'])) {
        $highlighted['books'][] = $book;
      }
    }

    return $this->formatResponse($highlighted, $_format);
  }

  /**
   * Search by keyword.
   *
   * Call back for the routers: elastique.search_limit, elastique.search_offset,
   * elastique.search.
   *
   * @param string $keyword
   *   The keyword to search.
   *
   * @param string $_format
   *   The format to be served.
   *
   * @param null|int $offset
   *   The offset.
   *
   * @param null|int $limit
   *   The maximum number of items to return.
   *
   * @return @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function search($keyword, $_format, $offset = 0, $limit = 50) {

    $data = [
      'books'  => [],
      'offset' => $offset,
      'limit'  => $limit,
    ];

    $query = "SELECT nfd.title, nfd.type, nfd.nid FROM {node_field_data} AS nfd
              LEFT JOIN {node__body} AS nb ON nb.entity_id = nfd.nid
              WHERE nfd.type = 'book' AND (nfd.title LIKE '{$keyword}%'
              OR nfd.title LIKE '%{$keyword}%' OR nfd.title
              LIKE '%{$keyword}' OR nfd.title = '{$keyword}'
              OR nb.body_value LIKE '{$keyword}%' OR nb.body_value
              LIKE '%{$keyword}%' OR nb.body_value
              LIKE '%{$keyword}' OR nb.body_value = '{$keyword}')";

    $records = db_query($query)->fetchAll();
    $data['total'] = count($records);

    $off = 0; // $offset
    $lim = 0; // limit
    foreach ($records as $record) {
      $book = $this->getNodeStorage()->load($record->nid);

      if (($off > $offset || $off == $offset)) {
        if ($lim < $limit) {
          $data['books'][] = $this->getBook($book);
          $lim++;
        }
      }

      // If limit is reached break away from the loop.
      if ($lim == $limit) {
        break;
      }

      $off++;
    }

    return $this->formatResponse($data, $_format);
  }

  /**
   * Format response as json.
   *
   * @param array $data
   *   A list of data to serve.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function json($data) {
    return new JsonResponse($data);
  }

  /**
   * Format response as csv.
   *
   * @param array $data
   *   A list of data to serve.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function csv($data) {
    $csv = $this->arrayToCsv($data);
    $response = new Response($csv);
    $response->headers->set('Content-Type', 'application/csv');
    return $response;
  }

  /**
   * Convert a list of data to a csv.
   *
   * @param array $data
   *   The list of data to convert.
   *
   * @param string $delimiter
   *   The delimiter of the csv.
   *
   * @return string
   *   The comma separated string.
   */
  protected function arrayToCsv($data, $delimiter = ';') {
    $csv = [];

    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $csv[] = $this->arrayToCsv($value);
        $csv[] = "\n";
      }
      else {
        $csv[] = "{$key}:{$value}";
      }
    }
    return implode($delimiter, $csv);
  }

  /**
   * Format response as xml.
   *
   * @param array $data
   *   A list of data to serve.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function xml($data) {
    $xml = new \SimpleXMLElement("<?xml version=\"1.0\"?><request></request>");
    $this->arrayToXml($data, $xml);
    $output = $xml->asXML();

    $response = new Response($output);
    $response->headers->set('Content-Type', 'application/xml');
    return $response;
  }

  /**
   * Convert an array to XML.
   *
   * @param array $data
   *   A list of data.
   *
   * @param \SimpleXMLElement $xml
   *   Xml object.
   */
  protected function arrayToXml($data, &$xml) {
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        if (!is_numeric($key)) {
          $subnode = $xml->addChild("$key");
          $this->arrayToXml($value, $subnode);
        }
        else {
          $this->arrayToXml($value, $xml);
        }
      }
      else {
        $xml->addChild("$key", "$value");
      }
    }
  }

  /**
   * Format response as html.
   *
   * @param array $data
   *   A list of data to serve.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response.
   */
  protected function html($data) {
    $html = is_array($data) ? $this->arrayToHtml($data) : $data;
    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html');
    return $response;
  }

  /**
   * Convert array to html string.
   *
   * @param array $data
   *   The list of data.
   *
   * @return string
   *  Html.
   */
  protected function arrayToHtml($data) {
    $output = '<ul>';
    foreach ($data as $key => $item) {
      $output .= '<li>' . (is_array($item) ? $key . ":" . $this->arrayToHtml($item) : $key . ":" . $item) . '</li>';
    }
    $output .= '</ul>';
    return $output;
  }

}
