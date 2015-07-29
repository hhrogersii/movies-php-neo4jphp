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

$neo4j = new Client();

$neo4j->getTransport()->setAuth('neo4j', 'asdf');

$app->get('/', function () {
	return file_get_contents(__DIR__.'/static/index.html');
});

$app->get('/graph', function (Request $request) use ($neo4j) {
	$limit = (integer)$request->get('limit', 50);
	$queryTemplate = <<<QUERY
MATCH (m:Movie)<-[:ACTED_IN]-(a:Person)
 RETURN m.title as movie, collect(a.name) as cast
 LIMIT {limit}
QUERY;

	$cypher = new Query($neo4j, $queryTemplate, array('limit'=>$limit));
	$results = $cypher->getResultSet();

	$actors = [];
	$nodes = [];
	$rels = [];
	foreach ($results as $result) {
		$target = count($nodes);
		$nodes[] = array('title' => $result['movie'], 'label' => 'movie');

		foreach ($result['cast'] as $name) {
			if (!isset($actors[$name])) {
				$actors[$name] = count($nodes);
				$nodes[] = array('title' => $name, 'label' => 'actor');
			}
			$rels[] = array('source' => $actors[$name], 'target' => $target);
		}
	}

	return json_encode(array(
		'nodes' => $nodes,
		'links' => $rels,
	));
});

$app->get('/search', function (Request $request) use ($neo4j) {
	$searchTerm = $request->get('q');
	$query = '(?i).*'.$searchTerm.'.*';
	$queryTemplate = <<<QUERY
MATCH (movie:Movie)
 WHERE movie.title =~ {query}
 RETURN movie
QUERY;

	$cypher = new Query($neo4j, $queryTemplate, array('query'=>$query));
	$results = $cypher->getResultSet();

	$movies = [];
	foreach ($results as $result) {
		$movies[] = array('movie' => $result['movie']->getProperties());
	}

	return json_encode($movies);
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

$app->get('/person/{name}', function ($name) use ($neo4j) {
	$queryTemplate = <<<QUERY
MATCH (person:Person {name:{name}})
 OPTIONAL MATCH (person)-[r]->(movie:Movie)
 RETURN person.name as name, person.born as born,
       collect({title:movie.title,
                job:head(split(lower(type(r)),'_')),
                role:r.roles}) as movies LIMIT 1
QUERY;

	$cypher = new Query($neo4j, $queryTemplate, array('name'=>$name));
	$results = $cypher->getResultSet();
	$result = $results[0];

	$person = array(
		'name' => $result['name'],
		'born' => $result['born'],
		'movies' => array()
	);
	foreach ($result['movies'] as $member) {
		$movieMember = array(
			'job' => $member['job'],
			'title' => $member['title'],
			'role' => array(),
		);

		if ($member['role']) {
			foreach ($member['role'] as $name) {
				$movieMember['role'][] = $name;
			}
		}

		$person['movies'][] = $movieMember;
	}

	return json_encode($person);
});

$app->run();
