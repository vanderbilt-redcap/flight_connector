<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

# This page uses Flight Connector data to form a social network edge graph
# Inspired by https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9825768/
# Code adapted from https://prod.tbilab.org/connect/

$pictureUrl = $module->getUrl("getPicture.php")."&NOAUTH";
$pid = getPid();
if (!$module->isSetup($pid)) {
    header("Location: ".$module->getUrl("install.php"));
}
$uniqueHash = $module->getProjectSetting(FlightConnectorExternalModule::UNIQUE_HASH_SETTING, $pid) ?? "";
if (($uniqueHash === "") || ($uniqueHash !== $_GET['h'])) {
    die("No access.");
}

$json = \REDCap::getDataDictionary($pid, "json");
$metadata = json_decode($json, TRUE);
$choices = getChoices($metadata);
$redcapData = getData($pid);
$projectTitle = shortenTitle(\REDCap::getProjectTitle());
$defaultHighlightedName= str_replace("&amp;", "&", preg_replace("/&#0?39;/", "'", htmlentities($_GET['highlightedName'] ?? "")));
$defaultHighlightedTopic = str_replace("&amp;", "&", preg_replace("/&#0?39;/", "'", htmlentities($_GET['highlightedTopic'] ?? "")));
$isMulti = isset($_GET['multi']) && ($_GET['multi'] == "1");
$combineWithGreater = isset($_GET['greater']) && ($_GET['greater'] == "1") && !$isMulti;
if ($isMulti) {
    $defaultHighlightedTopic = "";
    $defaultHighlightedName = "";
}

$width = 900;
$sliderWidth = 500;
$height = 680;
$numParts = 4;
$nodeColor = "#5c5c7a";
$nameHighlightColor = "#c49459";
$topicHighlightColor = "#d9d9e2";
$topicHighlightFontColor = "#666666";

echo "<!doctype html><html lang='en-US'>";
echo "<head>";
$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");
$migrateLink = $module->getUrl("jquery-migrate.min.js");
$jqueryUICSS = $module->getUrl("jquery-ui.min.css");
$jqueryUIJS = $module->getUrl("jquery-ui.min.js");
$autocompleteJS = $module->getUrl("autocomplete.js");
$d3Link = $module->getUrl("d3.v7.min.js");
$saveDiv = makeSaveDiv($module, "svg");
echo makeJS($module);
echo "	<meta http-equiv='Content-Type' content='text/html; charset=UTF-8' />
	<meta http-equiv='X-UA-Compatible' content='IE=edge,chrome=1' />
	<meta name='viewport' content='width=device-width,initial-scale=1' />	 	
	<link rel='profile' href='http://gmpg.org/xfn/11' />
	<link rel='stylesheet' href='https://use.typekit.net/ght7wyw.css' />
	<link rel='stylesheet' href='$jqueryUICSS' />
	<script type='text/javascript' src='$migrateLink'></script>
	<script type='text/javascript' src='$jqueryUIJS'></script>
	<script type='text/javascript' src='$autocompleteJS'></script>
	<script type='text/javascript' src='$d3Link'></script>
	<title>Flight Connector: $projectTitle</title>
	<link rel='stylesheet' href='$cssUrl' />
	<link rel='icon' href='$ftLogo' />";
echo "</head>";
echo "<body>";
echo makeHeader($module, "Flight Connector", 800, TRUE);

if (empty($redcapData)) {
    echo "<p class='centered max-width thickBorder padded'>No data have been entered.</p>";
    echo makeBothLinks($module, "nodeGraph");
    exit;
}
echo makeBothLinks($module, "nodeGraph");

$d3DataOriginal = [
    "nodes" => [],
    "links" => [],
];
if ($isMulti) {
    $personRadiusSize = 10;
    $fontSize = 10;
    $zoomFactor = 20;
} else if (count($redcapData) < 100) {
    $personRadiusSize = 15;
    $fontSize = 15;
    $zoomFactor = 35;
} else if (count($redcapData) < 200) {
    $personRadiusSize = 8;
    $fontSize = 8;
    $zoomFactor = 20;
} else {
    $personRadiusSize = 5;
    $fontSize = 0;
    $zoomFactor = 5;
}
# TODO - Dream List
# move scaling into JS so that they can scale off the number of nodes in the graph
# add traditional slider for zoomFactor next to slider-SVG
# v2.0? AI: download & parse CV for topics (top 500?) & construct graph based on those topics
# add X+ and X Common Topics data into same JavaScript along with Strongest 4
# EFS Retreat - activity based on software before lunch/break - dispel power dynamics - do something productive together
# Mobile view for EFS Retreat
# Set up first link to go to plus view (e.g., 1+)
# add link to self-written basic definitions to introduce to social network graph
# send Xaomin an email to show work
# make mouseovers better: both in graph and in line graph
# try another algorithm besides Force Undirected if available - Todd wants spatial relationships to be more meaningful
$padding = 2 * $personRadiusSize + 8;
$groups = [
    "contents" => "content",
    "methods" => "method",
];
$categories = [];
foreach (array_keys($groups) as $variable) {
    foreach ($choices[$variable] as $index => $label) {
        $categories[$label] = [];
    }
}
if ($combineWithGreater) {
    $greaterButton = "<button onclick='$(\"[name=greater]\").val(\"0\"); return true;' style='background-color: #b0d87a;'>Just This Strength</button>";
    $plus = "+";
} else if ($isMulti) {
    $greaterButton = "";
    $plus = "";
} else {
    $greaterButton = "<button onclick='$(\"[name=greater]\").val(\"1\"); return true;' style='background-color: #b0d87a;'>With Greater Strengths</button>";
    $plus = "";
}
if ($isMulti) {
    $strongestButton = "<button onclick='$(\"[name=multi]\").val(\"\"); $(\"#highlightedName\").val(\"\"); $(\"#highlightedTopic\").val(\"\"); return true;'>Show Just By Strength</button>";
} else {
    $strongestButton = "<button onclick='$(\"[name=multi]\").val(\"1\"); $(\"#highlightedName\").val(\"\"); $(\"#highlightedTopic\").val(\"\"); return true;'>Show Strongest $numParts</button>";
}
$allNames = [];
foreach ($redcapData as $row) {
    $name = $row['name'];
    $allNames[] = $name;
    list($first, $last) = NameMatcher::splitName($name);
    $fInitial = $first[0];
    $lInitial = $last[0];
    $initials = strtoupper("$fInitial$lInitial");
    $topics = [];
    foreach ($row as $field => $value) {
        foreach ($groups as $variable => $group) {
            if (preg_match("/^$variable"."___/", $field) && ($value == 1)) {
                $codedCategory = preg_replace("/^$variable"."___/", "", $field);
                $category = $choices[$variable][$codedCategory];
                $topics[] = formatCategory($category);
                $categories[$category][] = $name;
            }
        }
    }
    $node = [
        "id" => $name,
        "email" => "<a href='mailto:".$row['email']."'>".$row['email']."</a>",
        "group" => "person",
        "radius" => $personRadiusSize,
        "initials" => $initials,
        "topics" => $topics,
        "color" => $nodeColor,
        "fontColor" => "white",
    ];
    foreach (["department", "picture"] as $field) {
        if (isset($row[$field]) && $row[$field]) {
            $node[$field] = $row[$field];
        }
    }
    $d3DataOriginal["nodes"][] = $node;
}
foreach ($categories as $category => $names) {
    for ($i = 0; $i < count($names); $i++) {
        for ($j = $i + 1; $j < count($names); $j++) {
            createOrIncrementLink($d3DataOriginal["links"], $names[$i], $names[$j], [$category]);
        }
    }
}
$subgraphsAtStrength = makeSubgraphs($d3DataOriginal);
$maxStrength = empty($subgraphsAtStrength) ? 1 : max(array_keys($subgraphsAtStrength));

$sliderData = [];
for ($strength = 1; $strength <= $maxStrength; $strength++) {
    if (isset($subgraphsAtStrength[$strength])) {
        $data = $subgraphsAtStrength[$strength];
        if ($combineWithGreater) {
            combineWithGreaterSubgraphs($data, $subgraphsAtStrength, $strength, $maxStrength);
        }
        $memberCount = count($data["nodes"] ?? []);
        $densities = [];
        $numNodes = count($d3DataOriginal["nodes"] ?? []);
        if ($numNodes >= 2) {
            foreach ($data["nodes"] ?? [] as $node) {
                $nodeConnections = 0;
                foreach ($data["links"] ?? [] as $edge) {
                    if (($edge["source"] == $node["id"]) || ($edge["target"] == $node["id"])) {
                        $nodeConnections++;
                    }
                }
                $densities[] = $nodeConnections / ($numNodes - 1);
            }
        }
        $averageDensity = !empty($densities) ? array_sum($densities) / count($densities) : 0;
    } else {
        $memberCount = 0;
        $averageDensity = 0;
    }
    $sliderData[] = ["x" => $strength, "members" => $memberCount, "density" => $averageDensity];
}
$sliderJSON = json_encode($sliderData);

$strength = 1;
$myData = $d3DataOriginal;
if ($combineWithGreater) {
    combineWithGreaterSubgraphs($myData, $subgraphsAtStrength, $strength, $maxStrength);
}
addEdgeColors($myData["links"]);
$dataJSON = json_encode($myData);
if (empty($myData) || empty($myData["nodes"] ?? [])) {
    $saveDiv = "";
}
if ($isMulti) {
    $scale = ceil(sqrt($numParts));
    $subWidth = $width / $scale - 2;
    $subHeight = $height / $scale - 2;
    $html = "";
    for ($i = 1; $i <= $numParts; $i++) {
        $html .= "<div id='graph$i' style='width: $subWidth; height: $subHeight; float: left; border: 1px solid #AAA;'></div>";
    }
    $mainCall = "$('#graph').html(\"$html\");";
    $saveDiv = "";    // turn off for multi - they can reproduce at large scale if desired
    $subgraphJSONs = [];
    $subgraphData = [];
    $weights = [];
    $defaultWeight = 1;
    for ($i = 1; $i <= $numParts; $i++) {
        $foundCount = 0;
        $subgraphData[$i] = $subgraphsAtStrength[$defaultWeight] ?? [];
        $weights[$i] = $defaultWeight;
        $strengthsReversed = array_keys($subgraphsAtStrength);
        rsort($strengthsReversed);
        foreach ($strengthsReversed as $weight) {
            $data = $subgraphsAtStrength[$weight];
            if (!empty($data["nodes"])) {
                $foundCount++;
            }
            if ($foundCount == $i) {
                $subgraphData[$i] = $data;
                $weights[$i] = $weight;
                break;
            }
        }
    }
    for ($i = 1; $i <= $numParts; $i++) {
        if (empty($subgraphData[$i] ?? [])) {
            $subgraphJSONs[$i] = "{}";
        } else {
            addEdgeColors($subgraphData[$i]["links"]);
            $subgraphJSONs[$i] = json_encode($subgraphData[$i]);
        }
        $mainCall .= " $('#graph$i').append(chart($subWidth, $subHeight, {$subgraphJSONs[$i]}, {$weights[$i]}, $zoomFactor));";
    }
    $nameFinderHTML = "";
    $sliderCall = "";
    $callStem = "";
} else {
    $callStem = "";
    if (!empty($subgraphsAtStrength)) {
        $dataJSONs = [];
        for ($i = 1; $i <= $maxStrength; $i++) {
            $myData = $subgraphsAtStrength[$i] ?? ["nodes" => [], "links" => []];
            if ($combineWithGreater) {
                combineWithGreaterSubgraphs($myData, $subgraphsAtStrength, $i, $maxStrength);
            }
            addEdgeColors($myData["links"]);
            $dataJSONs[$i] = json_encode($myData);
        }
        $callStem .= "
            $('#numTopics').html(strength+'$plus Topic'+(strength === 1 ? '' : 's'));
            if (strength === 1) {
                return {$dataJSONs[1]};";
        for ($i = 2; $i <= $maxStrength; $i++) {
            $callStem .= "
            } else if (strength == $i) {
                return {$dataJSONs[$i]};";
        }
        $callStem .= "
        }";
    }
    $mainCall = "$('#graph').append(chart($width + $padding, $height + $padding, $dataJSON, $strength, $zoomFactor));";
    $sliderCall = "$('#configuration').html(slider($sliderWidth, 100, $sliderJSON));";
    $nameOptions = [];
    foreach ($allNames as $name) {
        if ($defaultHighlightedName == $name) {
            $optionHTML = "<option value=\"$name\" selected>$name</option>";
        } else {
            $optionHTML = "<option value=\"$name\">$name</option>";
        }
        $nameOptions[] = $optionHTML;
    }

    $topicOptions = [];
    foreach (array_keys($groups) as $variable) {
        foreach ($choices[$variable] ?? [] as $index => $label) {
            $id = $variable."___".$index;
            if ($defaultHighlightedTopic == $label) {
                $optionHTML = "<option value=\"$id\" selected>$label</option>";
            } else {
                $optionHTML = "<option value=\"$id\">$label</option>";
            }
            $topicOptions[] = $optionHTML;
        }
    }
    $nameFinderHTML = "<p class='centered'>
<label for='highlightName' style='padding-left: 3px; color: white; background-color: $nameHighlightColor; font-weight: bold;'>Name to Highlight: </label>
<select class='combobox' id='highlightName' onchange='FlightConnectorNodeGraph.highlightName(FlightConnectorNodeGraph.lastStrength, $(\"#highlightName :selected\").val(), false);'><option value=''></option>".implode("", array_values($nameOptions))."</select>
<label style='margin-left: 40px; padding-left: 3px; background-color: $topicHighlightColor; color: $topicHighlightFontColor; font-weight: bold;'for='highlightTopic'>Topic to Highlight: </label>
<select class='combobox' id='highlightTopic' onchange='FlightConnectorNodeGraph.highlightTopic(FlightConnectorNodeGraph.lastStrength, $(\"#highlightTopic :selected\").text(), false);'><option value=''></option>".implode("", array_values($topicOptions))."</select>
<button style='margin-left: 45px;' onclick='FlightConnectorNodeGraph.refreshData(FlightConnectorNodeGraph.getDataByStrength(FlightConnectorNodeGraph.lastStrength), FlightConnectorNodeGraph.lastStrength, \"\", \"\", false); return false;'>Reset</button>
</p>";
}


$hiddenHTML = "";
foreach ($_GET as $field => $value) {
    if (!in_array($field, ["strength", "instance", "pnid", "multi", "greater", "highlight", "highlightedName", "highlightedTopic"])) {
        $field = htmlentities($field);
        $value = htmlentities($value);
        $hiddenHTML .= "<input type='hidden' name='$field' value='$value' />";
    }
}
$hiddenHTML .= "
<input type='hidden' name='multi' value='0' />
<input type='hidden' name='greater' value='".($combineWithGreater ? "1" : "0")."' />
<input type='hidden' name='highlightedName' id='highlightedName' value=\"$defaultHighlightedName\" />
<input type='hidden' name='highlightedTopic' id='highlightedTopic' value=\"$defaultHighlightedTopic\" />";

if (($strength == 1) && !$plus) {
    $numTopics = "$strength Topic";
} else {
    $numTopics = "$strength$plus Topics";
}
$title = "Connections with <span id='numTopics'>$numTopics</span> in Common";

echo "
    <form method='GET' action='?'>
        $hiddenHTML
        <p class='centered'>$greaterButton $strongestButton</p>
        $nameFinderHTML
    </form>
    <h2>$projectTitle</h2>
    <div id='container'>
        <h3>$title</h3>
        <p class='centered'>Move your mouse over a circle/person or a line/connection to investigate it.<br/>Click it to investigate the entire cluster.</p>
		<div class='wrap'>
            <div id='configuration' style='max-width: $sliderWidth"."px; margin: 0 auto;'></div>
			<div id='main'>
			    <div style='width: 900px; float: left;'>
			        <div id='graph' style='border: 1px solid #888; width: 100%;'></div>
				    $saveDiv
                </div>
				<div id='main-graph-info' class='graph-info' style='padding: 8px; width: calc(100% - 960px); float: left; margin: 0 0 0 15px; height: 660px; overflow: scroll;'></div>
			</div>			
		</div>
	</div>";

?>
<style>
    .clusterMember { line-height: 0.8em; margin-top: 0.6em; margin-bottom: 0.6em; }
</style>
<script>

    class FlightConnectorNodeGraph {
        static holdClick = false;
        static lastStrength = <?= $strength ?>;

        static changeInt = function(sel, changeNum) {
            const newNum = parseInt($(sel).val()) + changeNum;
            if ((newNum <= $(sel).attr("max")) && (newNum >= $(sel).attr("min"))) {
                $(sel).val(newNum);
            }
        }

        static getNumberOfClusters = function(data) {
            const nodes = data.nodes ?? [];
            const edges = data.links ?? [];
            if (nodes.length === 0) {
                return 0;
            }

            const nodeIDIndexes = {};
            for (let nodeIndex = 0; nodeIndex < nodes.length; nodeIndex++) {
                nodeIDIndexes[nodes[nodeIndex].id] = nodeIndex;
            }

            const found = [];
            let numClusters = 0;
            for (let nodeIndex = 0; nodeIndex < nodes.length; nodeIndex++) {
                if (found.indexOf(nodeIndex) < 0) {
                    found.push(nodeIndex);
                    numClusters++;

                    const searchQueue = [nodeIndex];
                    while (searchQueue.length > 0) {
                        const searchNodeIndex = searchQueue.shift();
                        const searchNode = nodes[searchNodeIndex];
                        for (let edgeIndex = 0; edgeIndex < edges.length; edgeIndex++) {
                            const edge = edges[edgeIndex];
                            let otherIndex = -1;
                            if (edge.source === searchNode.id) {
                                otherIndex = nodeIDIndexes[edge.target];
                            } else if (edge.target === searchNode.id) {
                                otherIndex = nodeIDIndexes[edge.source];
                            }
                            if ((otherIndex >= 0) && (found.indexOf(otherIndex) < 0)) {
                                found.push(otherIndex);
                                searchQueue.push(otherIndex);
                            }
                        }
                    }
                }
            }
            return numClusters;
        }

        static summarizeClusters = function(data) {
            const nodes = data.nodes ?? [];
            const edges = data.links ?? [];
            if (nodes.length === 0) {
                return "";
            }

            let nodesLeft = nodes;
            const clusters = [];
            while (nodesLeft.length > 0) {
                const headNode = nodesLeft[0];
                const cluster = FlightConnectorNodeGraph.getCluster(headNode, nodesLeft, edges);
                clusters.push(cluster);

                const newNodesLeft = [];
                for (let i=0; i < nodesLeft.length; i++) {
                    const nodeId = nodesLeft[i].id;
                    let found = false;
                    for (let j=0; j < cluster.length; j++) {
                        if (cluster[j].id === nodeId) {
                            found = true;
                            break;
                        }
                    }
                    if (!found) {
                        newNodesLeft.push(nodesLeft[i]);
                    }
                }
                nodesLeft = newNodesLeft;
            }

            const numClusters = clusters.length;
            const clusteringStats = [];
            for (let i=0; i < numClusters; i++) {
                const num = i + 1;
                const cluster = clusters[i];
                const stats = FlightConnectorNodeGraph.getClusterNodeStats(cluster, edges, nodes);
                const lines = FlightConnectorNodeGraph.getInfoDataLines(stats.members, stats.densities, stats.centralities);
                clusteringStats.push('<h5 style=\"margin-bottom: 0;\">Cluster #'+num+'</h5>'+lines.join('<br/>'));
            }
            $('#main-graph-info').html('<h4>'+numClusters+' Cluster'+(numClusters === 1 ? '' : 's')+'</h4>'+clusteringStats.join(''));
        }

        static refreshData = function(data, strength, name, topic, isInitial) {
            if (!data.nodes || !data.links) {
                FlightConnectorNodeGraph.refreshGraph({nodes: [], links: []}, strength);
            }
            const topicHighlightColor = '<?= $topicHighlightColor ?>';
            const topicHighlightFontColor = '<?= $topicHighlightFontColor ?>'
            const nameHighlightColor = '<?= $nameHighlightColor ?>';
            let changed = (strength !== FlightConnectorNodeGraph.lastStrength);
            FlightConnectorNodeGraph.lastStrength = strength;
            const lastHighlightedTopic = $('#highlightedTopic').val();
            const lastHighlightedName = $('#highlightedName').val();
            if (!changed && (name === lastHighlightedName) && (topic === lastHighlightedTopic) && !isInitial) {
                return;
            }
            $('#highlightedTopic').val(topic);
            $('#highlightedName').val(name);
            if ((topic === "") && (name === "")) {
                $('#highlightedTopic').parent().find('.custom-combobox input').val(topic);
                $('#highlightedName').parent().find('.custom-combobox input').val(name);
                FlightConnectorNodeGraph.summarizeClusters(data);
                changed = true;
            }
            for (let i = 0; i < data.nodes.length; i++) {
                if (data.nodes[i].id === name) {
                    data.nodes[i].color = nameHighlightColor;
                    FlightConnectorNodeGraph.inspectAndDisplayNode(data.nodes[i]);
                    changed = true;
                }
                if ((data.nodes[i].topics.indexOf(topic) >= 0) && (data.nodes[i].color !== nameHighlightColor)) {
                    data.nodes[i].color = topicHighlightColor;
                    data.nodes[i].fontColor = topicHighlightFontColor;
                    changed = true;
                }
            }
            if (changed) {
                FlightConnectorNodeGraph.refreshGraph(data, strength);
            }
        }

        static highlightTopic = function(strength, topic, isInitial) {
            const name = $('#highlightedName').val();
            const data = FlightConnectorNodeGraph.getDataByStrength(strength);
            FlightConnectorNodeGraph.refreshData(data, strength, name, topic, isInitial);
        }

        static refreshGraph(data, strength) {
            $('#graph').html(updateChart(<?= $width + $padding ?>, <?= $height + $padding ?>, data, strength, <?= $zoomFactor ?>));
        }

        static highlightName = function(strength, name, isInitial) {
            const topic = $('#highlightedTopic').val();
            const data = FlightConnectorNodeGraph.getDataByStrength(strength);
            FlightConnectorNodeGraph.refreshData(data, strength, name, topic, isInitial);
        }

        static makeUnorderedList = function(list) {
            if (list.length === 0) {
                return "<p>No items.</p>";
            }
            return "<ul style='padding-left: 14px;'><li style='text-align: left;'>"+list.join("</li><li style='text-align: left;'>")+"</li></ul>";
        }

        static makePubMedLink = function (name) {
            return 'https://pubmed.ncbi.nlm.nih.gov/?term='+encodeURIComponent(name);
        }

        static inspectAndDisplayNode = function(node) {
            const pubMedLink = FlightConnectorNodeGraph.makePubMedLink(node.id);
            const department = node.department ? "<br/>"+node.department : "";
            const topicList = FlightConnectorNodeGraph.makeUnorderedList(node.topics);
            const picture = FlightConnectorNodeGraph.getPictureFromNode(node);
            $('#main-graph-info').html("<h3 style='margin-bottom: 0;'>"+node.id+"</h3><p style='margin-top: 0;' class='smaller'>"+picture+"["+node.email+"]<br/>[<a href='"+pubMedLink+"' target='_new'>Search PubMed</a>]"+department+"</p><h4>"+node.topics.length+" Topic"+((node.topics.length !== 1) ? "s" : "")+" Selected</h4>"+topicList);
        }

        static getPictureFromNode = function(node) {
            return node.picture ? "<br/><img src='<?= $pictureUrl ?>&fileId="+node.picture+"' alt='Picture' style='width: 100px; height: auto; margin-bottom: -2px;' />" : "";
        }

        static getCluster = function(node, nodes, edges) {
            let cluster = [];
            const nodeIndexes = {};
            for (let nodeIndex = 0; nodeIndex < nodes.length; nodeIndex++) {
                const currNodeId = nodes[nodeIndex]['id'];
                nodeIndexes[currNodeId] = nodeIndex;
                nodes[nodeIndex]["direct_edges"] = [];
                nodes[nodeIndex]["edge_indexes"] = [];
                for (let edgeIndex = 0; edgeIndex < edges.length; edgeIndex++) {
                    if (currNodeId === edges[edgeIndex]["source"]) {
                        nodes[nodeIndex]["direct_edges"].push(edges[edgeIndex]["target"]);
                        nodes[nodeIndex]["edge_indexes"].push(edgeIndex);
                    } else if (currNodeId === edges[edgeIndex]["target"]) {
                        nodes[nodeIndex]["direct_edges"].push(edges[edgeIndex]["source"]);
                        nodes[nodeIndex]["edge_indexes"].push(edgeIndex);
                    }
                }
                if (currNodeId === node.id) {
                    cluster.push(nodes[nodeIndex]);
                }
            }
            let origClusterLength;
            const maxIterations = 1000;
            let iteration = 0;
            do {
                iteration++;
                origClusterLength = cluster.length;
                const clusterIds = [];
                const nextCluster = [];
                for (let clusterIndex = 0; clusterIndex < cluster.length; clusterIndex++) {
                    if (cluster[clusterIndex].id) {
                        clusterIds.push(cluster[clusterIndex].id);
                    }
                }
                for (let clusterIndex = 0; clusterIndex < cluster.length; clusterIndex++) {
                    nextCluster.push(cluster[clusterIndex]);
                    const edgeIds = cluster[clusterIndex]["direct_edges"];
                    for(let neighborIndex = 0; neighborIndex < edgeIds.length; neighborIndex++) {
                        const neighborId = edgeIds[neighborIndex];
                        if (clusterIds.indexOf(neighborId) < 0) {
                            if (typeof nodeIndexes[neighborId] !== "undefined") {
                                const neighborNode = nodes[nodeIndexes[neighborId]];
                                nextCluster.push(neighborNode);
                                clusterIds.push(neighborNode.id);
                            }
                        }
                    }
                }
                cluster = nextCluster;
            } while ((origClusterLength < cluster.length) && (iteration < maxIterations));
            return cluster;
        }

        static getClusterNodeStats = function(cluster, edges, nodes) {
            // Adapted from http://hubrik.com/2015/11/16/sort-by-last-name-with-javascript/
            const compareByLastName = (a,b) => {
                const aName = a.split(" ");
                const bName = b.split(" ");
                const aLastName = aName[aName.length - 1];
                const bLastName = bName[bName.length - 1];
                if (aLastName < bLastName) return -1;
                if (aLastName > bLastName) return 1;
                return 0;
            }

            const members = [];
            const densities = [];
            const centralities = [];
            for (let clusterIndex = 0; clusterIndex < cluster.length; clusterIndex++) {
                const clusterNode = cluster[clusterIndex];
                if (clusterNode.id) {
                    members.push(clusterNode.id);
                    for (let edgeIndexesIndex = 0; edgeIndexesIndex < clusterNode.edge_indexes.length; edgeIndexesIndex++) {
                        const edgeIndex = clusterNode.edge_indexes[edgeIndexesIndex];
                        centralities.push(edges[edgeIndex].value);
                        densities.push(edges[edgeIndex].value / (nodes.length - 1));
                    }
                }
            }
            members.sort(compareByLastName);
            return {
                members: members,
                densities: densities,
                centralities: centralities
            };
        }

        // nodes is all nodes; edges is all edges
        static displayClusterInfo = function(node, nodes, edges) {
            const cluster = FlightConnectorNodeGraph.getCluster(node, nodes, edges);
            const stats = FlightConnectorNodeGraph.getClusterNodeStats(cluster, edges, nodes);
            const members = stats.members;
            const densities = stats.densities;
            const centralities = stats.centralities;

            const membersWithDescriptions = [];
            for (let memberIndex = 0; memberIndex < members.length; memberIndex++) {
                const member = members[memberIndex];
                for (let clusterIndex = 0; clusterIndex < cluster.length; clusterIndex++) {
                    const clusterNode =  cluster[clusterIndex];
                    if ((member === clusterNode.id) && (clusterNode.email)) {
                        const picture = FlightConnectorNodeGraph.getPictureFromNode(clusterNode);
                        const pubMedLink = FlightConnectorNodeGraph.makePubMedLink(member);
                        const newInfo = member+picture+"<span class='smaller'><br/>["+clusterNode.email+"]</a><br/>[<a href='"+pubMedLink+"' target='_new'>Search PubMed</a>]</span>";
                        membersWithDescriptions.push(newInfo);
                        break;
                    }
                }
            }

            const infoLines = FlightConnectorNodeGraph.getInfoDataLines(members, densities, centralities);
            $('#main-graph-info').html("<h3>Cluster</h3><p><button onclick='FlightConnectorNodeGraph.holdClick = false; $(\"#main-graph-info\").html(\"\"); return false;' class='smaller'>Stop Examining Cluster</button></p><p>"+infoLines.join("<br/>")+"</p><h4 style='margin-bottom: 0;'>Members</h4><p class='clusterMember'>"+membersWithDescriptions.join("</p><p class='clusterMember'>")+"</p>");
        }

        static getInfoDataLines = function(members, densities, centralities) {
            const infoLines = [];
            const average = array => array.reduce((a, b) => a + b) / array.length;
            infoLines.push("Number of Members: "+members.length);
            infoLines.push("<span style='text-decoration: underline dotted #888888;' title=\"The average of each node's number of connections divided by the number of possible connections.\">Average Density</span>: "+average(densities).toFixed(3));
            const centralityAverage = average(centralities);
            const centralityDefinition = "The average of each node's number of connections.";
            if (Number.isInteger(centralityAverage)) {
                infoLines.push("<span title=\""+centralityDefinition+"\" style='text-decoration: underline dotted #888888;'>Average Degree Centrality</span>: "+centralityAverage);
            } else {
                infoLines.push("<span title=\""+centralityDefinition+"\" style='text-decoration: underline dotted #888888;'>Average Degree Centrality</span>: "+centralityAverage.toFixed(3));
            }
            return infoLines;
        }

        static getDataByStrength = (strength) => {
            <?= $callStem ?>
            return {};
        }
    }
</script>

<script type='module'>
    const slider = (width, height, data) => {

        const shiftRight = 15;
        const defaultLineWidth = 3;
        const svg = d3.create("svg")
            .attr("width", width)
            .attr("height", height + 2);
        const container = svg.append("g");
        const box = (!'<?= $plus ?>') ? null : container.append("rect")
            .attr("x", shiftRight)
            .attr("y", 0)
            .attr("width", width - shiftRight)
            .attr("height", height)
            .attr("stroke-width", 0)
            .attr("fill", "#fdebec");
        const tooltip = container.append("g")
            .attr("transform", "translate("+width+", 0)");
        const line = container.append("line")
            .style("stroke", "#f69ca0")
            .style("stroke-width", defaultLineWidth)
            .attr("x1", shiftRight)
            .attr("y1", 0)
            .attr("x2", shiftRight)
            .attr("y2", height);

        const bisect = d3.bisector(d => d.x - 1).center;
        const setText = (tooltip, strength, members, density) => {
            tooltip.selectAll("text")
                .data([,])
                .join("text")
                .call(text => text
                    .attr("text-anchor", "end")
                    .selectAll("tspan")
                    .data([strength+"<?= $plus ?> Common Topic"+(((strength > 1) || ("<?= $plus ?>")) ? "s" : ""), members+" Member"+(members === 1 ? "" : "s"), "Average Density: "+density.toFixed(3)])
                    .join("tspan")
                    .attr("x", 0)
                    .attr("y", (a, i) => (i * 1.1 + 1)+"em")
                    .attr("font-size", 13)
                    .text(d => d));
        };

        const maxNumMembers = d3.max([1.0, d3.max(data, (d) => d.members)]);
        const maxStrength = d3.max(data, (d) => d.x);
        setText(tooltip, data[0]["x"], data[0]["members"], data[0]["density"]);

        const pointermoved = (event) => {
            const currX = d3.pointer(event)[0];
            if (currX < shiftRight) {
                line
                    .attr("x1", shiftRight)
                    .attr("x2", shiftRight);
                if (box) {
                    box
                        .attr('width', width - shiftRight)
                        .attr("x", shiftRight);
                }
                const i = 0;
                const strength = data[i]["x"];
                const density = data[i]["density"];
                const members = data[i]["members"];
                setText(tooltip, strength, members, density);
            } else {
                const strengthWidth = (width - shiftRight) / (maxStrength - 1);
                let i = bisect(data, x.invert(currX)) - 1;
                if (currX > 0.5 * strengthWidth + i * strengthWidth + shiftRight) {
                    // adjust to next when greater than 1/2 through the area
                    i++;
                }
                const strength = data[i]["x"];
                const density = data[i]["density"];
                const members = data[i]["members"];
                if (box) {
                    box
                        .attr('width', width - currX)
                        .attr("x", currX);
                }
                line
                    .attr("x1", currX)
                    .attr("x2", currX);
                setText(tooltip, strength, members, density);
                if (FlightConnectorNodeGraph.lastStrength !== strength) {
                    refreshGraphByStrength(strength);
                }
            }
        };
        svg.on("pointerenter pointermove", pointermoved);


        const x = d3.scaleTime()
            .domain(d3.extent(data, function(d) { return d.x; }))
            .range([ shiftRight, width ]);

        const y = d3.scaleLinear()
            .domain([0, 1])
            .range([ height, 0 ]);

        const chartTransform = "translate("+shiftRight+", 0)";
        const axis = d3.axisLeft(y);
        axis.ticks(4);
        axis.tickFormat("");
        container.append("g")
            .attr("transform", chartTransform)
            .call(axis);

        container.append("path")
            .datum(data)
            .attr("fill", "none")
            .attr("stroke", "#bebed3")
            .attr("stroke-width", 1)
            .attr("d", d3.line()
                .x(function(d) { return x(d.x) })
                .y(function(d) { return y(d.density) })
            );
        container.append("path")
            .datum(data)
            .attr("fill", "none")
            .attr("stroke", "#6a6a75")
            .attr("stroke-width", 1)
            .attr("d", d3.line()
                .x(function(d) { return x(d.x) })
                .y(function(d) { return y(d.members / maxNumMembers) })
            );

        return svg.node();
    }

    const chart = function(width, height, data, strength, zoomFactor) {
        const infoOb = $('#main-graph-info');
        if (data['nodes'].length === 0) {
            return "<div style='width: "+width+"px; height: "+height+"px;'><h3>No clusters with "+strength+" common topic"+((strength > 1) ? "s" : "")+"</h3></div>";
        }

        // The force simulation mutates links and nodes, so create a copy
        // so that re-evaluating this cell produces the same result.
        const links = data.links.map(d => ({...d}));
        const nodes = data.nodes.map(d => ({...d}));

        // Create a simulation with several forces.
        const simulation = d3.forceSimulation(nodes)
            .force("link", d3.forceLink(links).id(d => d.id))
            .force("charge", d3.forceManyBody())
            .force("center", d3.forceCenter(width / 2, height / 2))
            .force("collide", d3.forceCollide(d => zoomFactor))
            .on("tick", ticked);

        // Create the SVG container.
        const svg = d3.create("svg")
            .attr("width", width)
            .attr("height", height)
            .attr("viewBox", [0, 0, width, height])
            .attr("style", "max-width: 100%; height: auto;");

        const container = svg.append("g");

        const link = container.append("g")
            .selectAll()
            .data(links)
            .enter()
            .append("g");

        const edgeLine = link.append("line")
            .attr("stroke", d => d.color)
            .attr("stroke-opacity", 0.6)
            .attr("stroke-width", d => d.value / 2)
            .on("mouseover", (i, d) => {
                if (!FlightConnectorNodeGraph.holdClick) {
                    const commonList = FlightConnectorNodeGraph.makeUnorderedList(d.topics);
                    const commonHeader = "<h4>"+d.topics.length+" Common Topic"+((d.topics.length !== 1) ? "s" : "")+"</h4>";
                    const sourceTopics = makeDiffArray(d.source.topics, d.topics);
                    const sourceList = FlightConnectorNodeGraph.makeUnorderedList(sourceTopics);
                    const sourceHeader = "<h4>"+d.source.id+":<br/>"+sourceTopics.length+" Unique Topic"+((sourceTopics.length !== 1) ? "s" : "")+"</h4>";
                    const targetTopics = makeDiffArray(d.target.topics, d.topics);
                    const targetList = FlightConnectorNodeGraph.makeUnorderedList(targetTopics);
                    const targetHeader = "<h4>"+d.target.id+":<br/>"+targetTopics.length+" Unique Topic"+((targetTopics.length !== 1) ? "s" : "")+"</h4>";
                    infoOb.html("<h3>Connected Names</h3><h4 style='margin-bottom: 0;'>"+d.source.id+"</h4><p style='margin-top: 0;'>["+d.source.email+"]</p><h4 style='margin-bottom: 0;'>"+d.target.id+"</h4><p style='margin-top: 0;'>["+d.target.email+"]</p>"+commonHeader+commonList+sourceHeader+sourceList+targetHeader+targetList)
                }
            });

        const circles = container.append("g")
            .attr("stroke", "#000")
            .attr("stroke-width", 1.5)
            .attr("class", "node")
            .selectAll()
            .data(nodes)
            .enter()
            .append("g");

        const node = circles.append("circle")
            .attr("r", d => (d.radius))
            .attr("fill", d => d.color);
        node.call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended));

        const label = circles.append("text")
            .attr("text-anchor", "middle")
            .attr("alignment-baseline", "middle")
            .attr("fill", d => d.fontColor)
            .attr("stroke-width", 1)
            .attr("stroke", d => d.fontColor)
            .attr("font-size", "<?= $fontSize ?>px")
            .attr("font-weight", 500)
            .text(d => d.initials)
            .on("mouseover", (i, d) => {
                if (!FlightConnectorNodeGraph.holdClick) {
                    FlightConnectorNodeGraph.inspectAndDisplayNode(d);
                }
            })
            .on("click", (i, d) => {
                FlightConnectorNodeGraph.holdClick = true;
                FlightConnectorNodeGraph.displayClusterInfo(d, data.nodes, data.links);
            });
        label.call(d3.drag()
            .on("start", dragstarted)
            .on("drag", dragged)
            .on("end", dragended));

        container.append("text")
            .attr("x", width - 100)
            .attr("y", 20)
            .attr("fill", "#000")
            .attr("stroke-width", 1)
            .attr("stroke", "#000")
            .attr("font-size", "11px")
            .attr("font-weight", 500)
            .text(strength+"<?= $plus ?> common topic"+(((strength !== 1) || ('<?= $plus ?>')) ? "s" : ""))

        // Set the position attributes of links and nodes each time the simulation ticks.
        function ticked() {
            edgeLine
                .attr("x1", d => d.source.x)
                .attr("y1", d => d.source.y)
                .attr("x2", d => d.target.x)
                .attr("y2", d => d.target.y);

            node
                .attr("cx", d => d.x)
                .attr("cy", d => d.y);

            label
                .attr("x", d => d.x)
                .attr("y", d => d.y + 1);
        }

        // Reheat the simulation when drag starts, and fix the subject position.
        function dragstarted(event) {
            if (!event.active) simulation.alphaTarget(0.3).restart();
            event.subject.fx = event.subject.x;
            event.subject.fy = event.subject.y;
        }

        // Update the subject (dragged node) position during drag.
        function dragged(event) {
            event.subject.fx = event.x;
            event.subject.fy = event.y;
        }

        // Restore the target alpha so the simulation cools after dragging ends.
        // Unfix the subject position now that it’s no longer being dragged.
        function dragended(event) {
            if (!event.active) simulation.alphaTarget(0);
            event.subject.fx = null;
            event.subject.fy = null;
        }

        function makeDiffArray(baseArray, subset) {
            const diff = [];
            for (let i = 0; i < baseArray.length; i++) {
                const elem = baseArray[i];
                if (subset.indexOf(elem) < 0) {
                    diff.push(elem);
                }
            }
            return diff;
        }

        return svg.node();
    };

    function refreshGraphByStrength(strength) {
        const data = FlightConnectorNodeGraph.getDataByStrength(strength);
        const name = $("#highlightedName").val();
        const topic = $("#highlightedTopic").val();
        FlightConnectorNodeGraph.refreshData(data, strength, name, topic, false);
    }

    $(document).ready(() => {
        <?= $mainCall ?>
        <?= $sliderCall ?>
        window.updateChart = chart;
        const highlightedName = '<?= $defaultHighlightedName ?>';
        if (highlightedName) {
            FlightConnectorNodeGraph.highlightName(<?= $strength ?>, highlightedName, true);
        }
        const highlightedTopic = '<?= $defaultHighlightedTopic ?>';
        if (highlightedTopic) {
            FlightConnectorNodeGraph.highlightTopic(<?= $strength ?>, highlightedTopic, true);
        }
        if (!highlightedTopic && !highlightedName) {
            FlightConnectorNodeGraph.summarizeClusters(<?= $dataJSON ?>);
        }
    });
</script>


<?php

# Nick Strayer, Siwei Zhang, Lydia Yao, Tess Vessels, Cosmin A Bejan, Ryan S Hsi, Jana K Shirey-Rice, Justin M Balko,
# Douglas B Johnson, Elizabeth J Phillips, Alex Bick, Todd L Edwards, Digna R Velez Edwards, Jill M Pulley, Quinn S Wells,
# Michael R Savona, Nancy J Cox, Dan M Roden, Douglas M Ruderfer, Yaomin Xu, Interactive network-based clustering and
# investigation of multimorbidity association matrices with associationSubgraphs, Bioinformatics, Volume 39, Issue 1,
# January 2023, btac780, https://doi.org/10.1093/bioinformatics/btac780
function makeSubgraphs($d3Data) {
    $nodes = $d3Data["nodes"];
    $allNodesByName = [];
    foreach ($nodes as $node) {
        $allNodesByName[$node["id"]] = $node;
    }
    $edges = $d3Data["links"];

    $subgraphs = [];
    $nodesByName = [];
    for ($i = 0; $i < count($edges); $i++) {
        $weight = $edges[$i]["value"];
        $nodeAName = $edges[$i]["source"];
        $nodeBName = $edges[$i]["target"];
        $topics = $edges[$i]["topics"];
        if (!isset($subgraphs[$weight])) {
            $subgraphs[$weight] = ["nodes" => [], "links" => []];
            $nodesByName[$weight] = [];
        }
        if (!isset($nodesByName[$weight][$nodeAName])) {
            $nodesByName[$weight][$nodeAName] = $allNodesByName[$nodeAName];
        }
        if (!isset($nodesByName[$weight][$nodeBName])) {
            $nodesByName[$weight][$nodeBName] = $allNodesByName[$nodeBName];
        }
        createOrIncrementLink($subgraphs[$weight]["links"], $nodeAName, $nodeBName, $topics);
    }
    for ($weight = 1; $weight <= max(array_keys($subgraphs)); $weight++) {
        if (!isset($subgraphs[$weight])) {
            $subgraphs[$weight] = ["nodes" => [], "links" => []];
        } else {
            $subgraphs[$weight]["nodes"] = array_values($nodesByName[$weight]);
        }
    }
    return $subgraphs;
}

function getNodesFromEdges($edges, $field) {
    $nodes = [];
    foreach ($edges as $edge) {
        if (isset($edge[$field])) {
            $nodes[] = $edge[$field];
        }
    }
    return $nodes;
}

function createOrIncrementLink(&$edges, $name1, $name2, $topics)
{
    foreach ($topics as &$topic) {
        $topic = formatCategory($topic);
    }
    $weight = count($topics);
    $found = FALSE;
    foreach ($edges as &$edge) {
        if (
            (($edge['source'] == $name1) && ($edge['target'] == $name2))
            || (($edge['source'] == $name2) && ($edge['target'] == $name1))
        ) {
            $edge['value'] += $weight;
            $edge["topics"] = array_merge($edge["topics"], $topics);
            $found = TRUE;
            break;
        }
    }
    if (!$found) {
        $edges[] = [
            "source" => $name1,
            "target" => $name2,
            "value" => $weight,
            "topics" => $topics,
        ];
    }
}

function formatCategory($topic) {
    $maxReplacements = 100;
    $i = 0;
    while (preg_match("/\S\/\S/", $topic, $matches) && ($i < $maxReplacements)) {
        $match = $matches[0];
        $newItem = str_replace("/", " / ", $match);
        $topic = str_replace($match, $newItem, $topic);
        $i++;
    }
    return $topic;
}

function addEdgeColors(&$edges) {
    for ($i = 0; $i < count($edges); $i++) {
        $weight = $edges[$i]["value"];
        $edges[$i]["color"] = "#999";
    }
}

function getEdgeLegend($edges) {
    $colorWheel = [
        "#d4d4eb",   // blue
        "#bebed3",   // blue
        "#9494a4",   // blue
        "#6a6a75",   // blue
        "#f69ca0",   // red
        "#dd8c90",   // red
        "#ac6d70",   // red
        "#7b4e50",   // red
        "#b0d87a",   // red
        "#9ec26d",   // green
        "#7b9755",   // green
        "#586c3c",   // green
        "#f6ba70",   // red
        "#dda764",   // orange
        "#ac824e",   // orange
        "#7b5d37",   // orange
    ];
    $weights = [];
    foreach ($edges as $edge) {
        $value = $edge["value"];
        if (!in_array($value, $weights)) {
            $weights[] = $value;
        }
    }
    $legend = [];
    if (count($weights) == 1) {
        $legend[$weights[0]] = "#999";
    } else {
        foreach ($weights as $i => $weight) {
            $legend[$weight] = $colorWheel[$i % count($colorWheel)];
        }
    }
    return $legend;
}

function getLegendHTML($legend) {
    if (count($legend) <= 1) {
        return "";
    }
    $htmlNodes = [];
    foreach ($legend as $weight => $color) {
        $htmlNodes[] = "<span style='white-space: nowrap; padding: 10px;'><span style='background-color: $color; border: 1px solid black;'>&nbsp;&nbsp;&nbsp;</span> $weight connections</span>";
    }
    return "<div style='border: 1px solid #888; padding: 6px; width: 1000px; text-align: center; word-wrap: anywhere; line-height: 2em;'><h4 style='margin-top: 0; margin-bottom: 0;'>Legend</h4>".implode("", $htmlNodes)."</div>";
}

function combineWithGreaterSubgraphs(&$myData, $subgraphsAtStrength, $strength, $maxStrength) {
    for ($i = $strength + 1; $i <= $maxStrength; $i++) {
        $strengthSubgraph = $subgraphsAtStrength[$i] ?? [];
        $myDataIds = [];
        foreach ($myData["nodes"] as $node) {
            $myDataIds[] = $node["id"];
        }
        foreach ($strengthSubgraph["nodes"] as $node) {
            $id = $node["id"];
            if (!in_array($id, $myDataIds)) {
                $myData["nodes"][] = $node;
            }
        }
        $myData["links"] = array_merge($myData["links"], $strengthSubgraph["links"] ?? []);
    }
}