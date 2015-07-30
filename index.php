<?php
use Silex\Application,
	Symfony\Component\HttpFoundation\Request,
	Everyman\Neo4j\Client,
	Everyman\Neo4j\Cypher\Query;

require __DIR__.'/vendor/autoload.php';

$app = new Application();
$app->after(function (Request $request, Symfony\Component\HttpFoundation\Response $response) {
    $response->headers->set('Access-Control-Allow-Origin', '*');
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
// header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Request-With');
// header('Access-Control-Allow-Credentials: true');
});
$app['debug'] = true;

$neo4j = new Client('52.10.156.36', 7474);

$neo4j->getTransport()->setAuth('neo4j', 'rootpass');

$app->get('/', function () {
	return file_get_contents(__DIR__.'/static/index.html');
});

$app->get('/graph', function (Request $request) use ($neo4j) {
	$limit = (integer)$request->get('limit', 50);
	$queryTemplate = <<<QUERY
MATCH (n)-[r]->(p)
 RETURN n.name as name, n.email as email,
	collect({email:p.email, name:p.name, score:r.frequency}) as knows
	LIMIT {limit}
QUERY;

	$cypher = new Query($neo4j, $queryTemplate, array('limit'=>$limit));
	$results = $cypher->getResultSet();

	$knows = [];
	$nodes = [];
	$rels = [];
	foreach ($results as $result) {
		$target = count($nodes);
		$nodes[] = array('name' => $result['name'], 'email' => $result['email']);

		foreach ($result['knows'] as $contact) {

			$contactId = $contact['email'];
			if (!isset($knows[$contactId])) {
				$knows[$contactId] = count($nodes);
				$nodes[] = array('name' => $contact['name'], 'email' => $contact['email']);
			}
			$rels[] = array('source' => $knows[$contactId], 'target' => $target, 'score' => $contact['score']);
		}
	}

	return json_encode(array(
		'nodes' => $nodes,
		'links' => $rels,
	));

});


$app->get('/short', function (Request $request) use ($neo4j) {
	$start = $request->get('start');
	$stop = $request->get('stop');
	// $start = 'kbrown@sugarcrm.com';
	// $stop = 'mmarum@sugarcrm.com';

// $paths = $startNode->findPathsTo($endNode)
//     ->setAlgorithm(PathFinder::AlgorithmDijkstra)
//     ->setMaxDepth(5)
//     ->setCostProperty('distance')
//     ->setDefaultCost(2)
//     ->getPaths()

// 	$queryTemplate = <<<QUERY
// MATCH (a { email:"{startEmail}" }),
//   (b { email:"{stopEmail}" }),
//   p = shortestPath((a)-[*..15]-(b))
//   RETURN p
// QUERY;

	$queryString = 'MATCH (a { email:"'.$start.'" }), (b { email:"'.$stop.'" }), p = shortestPath((a)-[*..15]-(b)) RETURN nodes(p) as n, relationships(p) as r';

	// $cypher = new Query($neo4j, $queryString, array('startEmail' => $start, 'stopEmail' => $stop));
	$cypher = new Query($neo4j, $queryString);
	$results = $cypher->getResultSet();
	var_dump($results);
	return json_encode($results);

	$knows = [];
	$nodes = [];
	$rels = [];
	foreach ($results as $result) {
		// print_r($result);
	// 	$target = count($nodes);
	// 	$nodes[] = array('name' => $result['name'], 'email' => $result['email']);

	// 	// foreach ($result['r'] as $contact) {

	// 	// 	$contactId = $contact['email'];
	// 	// 	if (!isset($knows[$contactId])) {
	// 	// 		$knows[$contactId] = count($nodes);
	// 	// 		$nodes[] = array('name' => $contact['name'], 'email' => $contact['email']);
	// 	// 	}
	// 	// 	$rels[] = array('source' => $knows[$contactId], 'target' => $target, 'score' => $contact['score']);
	// 	// }
	}

	return json_encode(array(
		'nodes' => $nodes,
		'links' => $rels,
	));





	// print_r(var_export($results, true));
	// $query = $cypher->getQuery();
	// $params = $cypher->getParameters();
	// print_r(var_export($queryString, true));
	// print_r(var_export($params, true));

	// $nodes = [];
	// foreach ($results as $row) {

	// 	foreach ($row['p'] as $node) {
	// 		print_r(json_encode($node));

	// 		// $nodes[] = $node['email'];
	// 	}
	// }

	// return json_encode($nodes);
});

$app->get('/movie/{title}', function ($title) use ($neo4j) {
	$queryTemplate = <<<QUERY
MATCH (movie:Movie {title:{title}})
 OPTIONAL MATCH (movie)<-[r]-(person:Person)
 RETURN movie.title as title, movie.released as released, movie.tagline as tagline,
       collect({name:person.name,
                job:head(split(lower(type(r)),'_')),
                role:r.roles}) as cast LIMIT 1
QUERY;

	$cypher = new Query($neo4j, $queryTemplate, array('title'=>$title));
	$results = $cypher->getResultSet();
	$result = $results[0];

	$movie = array(
		'title' => $result['title'],
		'released' => $result['released'],
		'tagline' => $result['tagline'],
		'cast' => array()
	);
	foreach ($result['cast'] as $member) {
		$castMember = array(
			'job' => $member['job'],
			'name' => $member['name'],
			'role' => array(),
		);

		if ($member['role']) {
			foreach ($member['role'] as $name) {
				$castMember['role'][] = $name;
			}
		}

		$movie['cast'][] = $castMember;
	}

	return json_encode($movie);
});

$app->run();
