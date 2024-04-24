<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

# This page uses Flight Connector data to form a social network edge graph
# Inspired by https://www.ncbi.nlm.nih.gov/pmc/articles/PMC9825768/
# Code adapted from https://prod.tbilab.org/connect/

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
$combineWithGreater = isset($_GET['greater']) && ($_GET['greater'] == "1");
if ($isMulti) {
    $defaultHighlightedTopic = "";
    $defaultHighlightedName = "";
}

$width = 900;
$height = 680;
$numParts = 4;
$nodeColor = "#5c5c7a";
$nameHighlightColor = "#c49459";
$topicHighlightColor = "#698149";

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
    $zoomFactor = 10;
}
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
    ];
    if (isset($row['department']) && $row['department']) {
        $node["department"] = $row["department"];
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
if (isset($_GET['strength'])) {
    $strength = $_GET['strength'] ?? 1;
    $myData = $subgraphsAtStrength[$strength] ?? [];
} else {
    $strength = 1;
    $myData = $d3DataOriginal;
}
if ($combineWithGreater) {
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
    $weights = [];
    $defaultWeight = 1;
    for ($i = 1; $i <= $numParts; $i++) {
        $foundCount = 0;
        $subgraphJSONs[$i] = $subgraphsAtStrength[$defaultWeight] ?? "{}";
        $weights[$i] = $defaultWeight;
        $strengthsReversed = array_keys($subgraphsAtStrength);
        rsort($strengthsReversed);
        foreach ($strengthsReversed as $weight) {
            $data = $subgraphsAtStrength[$weight];
            if (!empty($data["nodes"])) {
                $foundCount++;
            }
            if ($foundCount == $i) {
                $subgraphJSONs[$i] = json_encode($data);
                $weights[$i] = $weight;
                break;
            }
        }
    }
    for ($i = 1; $i <= $numParts; $i++) {
        $mainCall .= " $('#graph$i').append(chart($subWidth, $subHeight, {$subgraphJSONs[$i]}, {$weights[$i]}, $zoomFactor));";
    }
    $nameFinderHTML = "";
} else {
    $mainCall = "$('#graph').append(chart($width + $padding, $height + $padding, $dataJSON, $strength, $zoomFactor));";
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
    $nameFinderHTML = "<p class='centered'><label for='highlight' style='color: $nameHighlightColor; font-weight: bold;'>Name to Highlight: </label><select class='combobox' id='highlight' onchange='FlightConnectorNodeGraph.highlightName($(\"#highlight :selected\").val(), false);'><option value=''></option>".implode("", array_values($nameOptions))."</select><label style='margin-left: 40px; color: $topicHighlightColor; font-weight: bold;'for='highlightTopic'>Topic to Highlight: </stylelabel><select class='combobox' id='highlightTopic' onchange='FlightConnectorNodeGraph.highlightTopic($(\"#highlightTopic :selected\").text(), false);'><option value=''></option>".implode("", array_values($topicOptions))."</select></p>";
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
$strength = htmlentities($strength);

if (!$combineWithGreater) {
    $greaterButton = "<button onclick='$(\"[name=greater]\").val(\"1\"); return true;' style='background-color: #b0d87a;'>With Greater Strengths</button>";
    $plus = "";
} else {
    $greaterButton = "<button onclick='$(\"[name=greater]\").val(\"0\"); return true;' style='background-color: #b0d87a;'>Just This Strength</button>";
    $plus = "+";
}
if (($strength == 1) && !$plus) {
    $numTopics = "$strength Topic";
} else {
    $numTopics = "$strength$plus Topics";
}
$title = "Connections with $numTopics in Common";

echo "
    <form method='GET' action='?'>
        $hiddenHTML
        <p class='centered'><label for='strength'>Number of Common Topics: </label><input type='number' min='1' max='$maxStrength' name='strength' value='$strength' id='strength' style='width: 35px;' onchange='$(this).closest(\"form\").submit();'/> <button onclick='FlightConnectorNodeGraph.changeInt(\"#strength\", 1); return true;'>+</button> <button onclick='FlightConnectorNodeGraph.changeInt(\"#strength\", -1); return true;'>-</button> $greaterButton <button onclick='$(\"[name=multi]\").val(\"1\"); $(\"#highlightName\").val(\"\"); $(\"#highlightTopic\").val(\"\"); return true;'>Show Strongest $numParts</button></p>
        $nameFinderHTML
    </form>
    <h2>$projectTitle</h2>
    <div id='container'>
        <h3>$title</h3>
        <p class='centered'>Move your mouse over a circle/person or a line/edge/connection to investigate it.<br/>Click it to investigate the entire cluster.</p>
		<div class='wrap'>
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
<script>

    class FlightConnectorNodeGraph {
        holdClick = false;

        static changeInt = function(sel, changeNum) {
            const newNum = parseInt($(sel).val()) + changeNum;
            if ((newNum <= $(sel).attr("max")) && (newNum >= $(sel).attr("min"))) {
                $(sel).val(newNum);
            }
        }

        static refreshData = function(name, topic, isInitial) {
            const data = <?= $dataJSON ?>;
            const topicHighlightColor = '<?= $topicHighlightColor ?>';
            const nameHighlightColor = '<?= $nameHighlightColor ?>';
            let changed = false;
            const lastHighlightedTopic = $('#highlightedTopic').val();
            const lastHighlightedName = $('#highlightedName').val();
            if ((name === lastHighlightedName) && (topic === lastHighlightedTopic) && !isInitial) {
                return;
            }
            $('#highlightedTopic').val(topic);
            $('#highlightedName').val(name);
            for (let i = 0; i < data.nodes.length; i++) {
                if (data.nodes[i].id === name) {
                    data.nodes[i].color = nameHighlightColor;
                    FlightConnectorNodeGraph.inspectAndDisplayNode(data.nodes[i]);
                    changed = true;
                }
                if ((data.nodes[i].topics.indexOf(topic) >= 0) && (data.nodes[i].color !== nameHighlightColor)) {
                    data.nodes[i].color = topicHighlightColor;
                    changed = true;
                }
            }
            if (changed) {
                FlightConnectorNodeGraph.refreshGraph(data);
            }
        }

        static highlightTopic = function(topic, isInitial) {
            const name = $('#highlightedName').val();
            FlightConnectorNodeGraph.refreshData(name, topic, isInitial);
        }

        static refreshGraph(data) {
            $('#graph').html(updateChart(<?= $width + $padding ?>, <?= $height + $padding ?>, data, <?= $strength ?>, <?= $zoomFactor ?>));
        }

        static highlightName = function(name, isInitial) {
            const topic = $('#highlightedTopic').val();
            FlightConnectorNodeGraph.refreshData(name, topic, isInitial);
        }

        static makeUnorderedList = function(list) {
            if (list.length === 0) {
                return "<p>No items.</p>";
            }
            return "<ul style='padding-left: 14px;'><li style='text-align: left;'>"+list.join("</li><li style='text-align: left;'>")+"</li></ul>";
        }

        static inspectAndDisplayNode = function(node) {
            const pubMedLink = 'https://pubmed.ncbi.nlm.nih.gov/?term='+encodeURIComponent(node.id);
            const department = node.department ? "<br/>"+node.department : "";
            const topicList = FlightConnectorNodeGraph.makeUnorderedList(node.topics);
            $('#main-graph-info').html("<h3 style='margin-bottom: 0;'>"+node.id+"</h3><p style='margin-top: 0;'>["+node.email+"]<br/>[<a href='"+pubMedLink+"' target='_new'>Search PubMed</a>]"+department+"</p><h4>"+node.topics.length+" Topic"+((node.topics.length !== 1) ? "s" : "")+" Selected</h4>"+topicList);
        }

        // nodes is all nodes; edges is all edges
        static displayClusterInfo = function(node, nodes, edges) {
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
                    clusterIds.push(cluster[clusterIndex].id);
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
                members.push(clusterNode.id);
                for (let edgeIndexesIndex = 0; edgeIndexesIndex < clusterNode.edge_indexes.length; edgeIndexesIndex++) {
                    const edgeIndex = clusterNode.edge_indexes[edgeIndexesIndex];
                    centralities.push(edges[edgeIndex].value);
                    densities.push(edges[edgeIndex].value / (nodes.length - 1));
                }
            }
            members.sort(compareByLastName);
            const average = array => array.reduce((a, b) => a + b) / array.length;

            const infoLines = [];
            infoLines.push("Number of Members: "+members.length);
            infoLines.push("Average Density: "+average(densities).toFixed(3));
            const centralityAverage = average(centralities);
            if (Number.isInteger(centralityAverage)) {
                infoLines.push("Average Degree Centrality: "+centralityAverage);
            } else {
                infoLines.push("Average Degree Centrality: "+centralityAverage.toFixed(3));
            }

            $('#main-graph-info').html("<h3>Cluster</h3><p><button onclick='FlightConnectorNodeGraph.holdClick = false; $(\"#main-graph-info\").html(\"\"); return false;' class='smaller'>Stop Examining Cluster</button></p><p>"+infoLines.join("<br/>")+"</p><h4 style='margin-bottom: 0;'>Members</h4><p style='margin-top: 0;'>"+members.join("<br/>")+"</p>");
        }
    }
</script>

<script type='module'>
    const chart = function(width, height, data, strength, zoomFactor) {
        const infoOb = $('#main-graph-info');
        if (data['nodes'].length === 0) {
            return "<h3>No clusters with "+strength+" common topic(s).</h3>";
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
            .attr("stroke", "#999")
            .attr("stroke-opacity", 0.6)
            .attr("stroke-width", d => Math.sqrt(d.value))
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
            .attr("fill", "#fff")
            .attr("stroke-width", 1)
            .attr("stroke", "#fff")
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
            .text(strength+"<?= $plus ?> common topic"+((strength !== 1) ? "s" : ""))

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
    $(document).ready(() => {
        <?= $mainCall ?>
        window.updateChart = chart;
        const highlightedName = '<?= $defaultHighlightedName ?>';
        if (highlightedName) {
            FlightConnectorNodeGraph.highlightName(highlightedName, true);
        }
        const highlightedTopic = '<?= $defaultHighlightedTopic ?>';
        if (highlightedTopic) {
            FlightConnectorNodeGraph.highlightTopic(highlightedTopic, true);
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
            $nodes[] =$edge[$field];
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