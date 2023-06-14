<?php

namespace Vanderbilt\FlightConnectorExternalModule;

use \ExternalModules\ExternalModules;

require_once(__DIR__."/FlightConnectorExternalModule.php");
require_once(__DIR__."/base.php");

$pid = getPid();
$projectTitle = shortenTitle(\REDCap::getProjectTitle());
$cssUrl = $module->getUrl("connect.css");
$ftLogo = $module->getUrl("flight_tracker_icon.png");
$json = \REDCap::getDataDictionary($pid, "json");
$metadata = json_decode($json, TRUE);
$contentsLabel = "Contents";
$methodsLabel = "Methods";
$peopleLabel = "People";
foreach ($metadata as $row) {
    if ($row['field_name'] == "contents") {
        $contentsLabel = $row['field_label'];
    } else if ($row['field_name'] == "methods") {
        $methodsLabel = $row['field_label'];
    }
}

if (!$module->isSetup($pid)) {
    header("Location: ".$module->getUrl("install.php"));
}
$uniqueHash = $module->getProjectSetting(FlightConnectorExternalModule::UNIQUE_HASH_SETTING, $pid) ?? "";
if (($uniqueHash === "") || ($uniqueHash !== $_GET['h'])) {
    die("No access.");
}

echo "<!doctype html><html lang='en-US'>";
echo "<head>";
$migrateLink = $module->getUrl("jquery-migrate.min.js");
$jqueryUICSS = $module->getUrl("jquery-ui.min.css");
$jqueryUIJS = $module->getUrl("jquery-ui.min.js");
$autocompleteJS = $module->getUrl("autocomplete.js");
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
	<title>Flight Connector</title>
	<link rel='stylesheet' href='$cssUrl' />
	<link rel='icon' href='$ftLogo' />";
echo "</head>";
echo "<body>";
echo makeHeader($module, "Flight Connector");

$redcapData = getData($pid);
if (empty($redcapData)) {
    echo "<p class='centered max-width thickBorder padded'>No data have been entered.</p>";
    echo makePublicSurveyLink($module);
    exit;
}
$choices = [];
foreach (array_keys(FlightConnectorExternalModule::FIELDS) as $field) {
    $choices[$field] = downloadChoicesForField($module, $pid, $field);
}
$inputData = makeD3Data($redcapData, $choices);
$matchedItems = [];
foreach (array_keys(FlightConnectorExternalModule::FIELDS) as $field) {
    $matchedItems[$field] = [];
    foreach ($inputData[$field] as $ary) {
        $matchedItems[$field][] = $ary['name'];
    }
}
$inputJSON = json_encode($inputData);

echo makePublicSurveyLink($module);

$d3Link = $module->getUrl("d3.v3.min.js");

$saveDiv = makeSaveDiv($module, "svg");

$options = [];
$options[] = "<option value='' selected></option>";
foreach ($choices as $field => $fieldChoices) {
    foreach ($fieldChoices as $index => $label) {
        if (in_array($label, $matchedItems[$field])) {
            $label = str_replace("'", "\\'", $label);
            $options[] = "<option value='$label' class='select_$field'>$label</option>";
        }
    }
}
foreach ($redcapData as $row) {
    $name = str_replace("'", "\\'", $row['name']);
    $options[] = "<option value='$name' class='select_scholars'>$name</option>";
}
$optionHTML = implode("", $options);

$radioConfig = [
    "contents" => $contentsLabel,
    "scholars" => $peopleLabel,
    "methods" => $methodsLabel,
];
$radios = [];
foreach ($radioConfig as $filterTerm => $label) {
    $radios[] = "<input type='radio' name='filter' value='$filterTerm' id='$filterTerm' onclick='FlightConnector.filterSelect(this);' /><label for='$filterTerm'>$label</label>";
}
$radioHTML = implode("&nbsp;&nbsp;&nbsp;&nbsp;", $radios);

$conjugation = makeConjunction([$contentsLabel, $methodsLabel, $peopleLabel]);

echo "
    <h2>$projectTitle</h2>
    <div id='container'>
		<div class='wrap'>
			<div id='main'>
			    <p style='text-align: center;'>$radioHTML<br/>
			    <select items='$conjugation' class='combobox' id='search'>$optionHTML</select> <button onclick='const val = $(\"#search option:selected\").val(); window.location.hash = FlightConnector.makeHash(val); return false;' style='margin-left: 50px;'>Search</button></p>
				<div id='main-graph-info' class='graph-info'></div>
				<div id='graph'></div>
				$saveDiv
				<div id='list-graph-info' class='graph-info'></div>
				<script type='text/javascript' src='$d3Link'></script>
			</div>			
		</div>
				
		</div>
	</div>";

$numRows = count($redcapData);
if ((count($choices['methods']) > 80) || (count($choices['contents']) > 80)) {
    $defaultFontSize = 9;
} else if ((count($choices['methods']) > 40) || (count($choices['contents']) > 40)) {
    $defaultFontSize = 12;
} else {
    $defaultFontSize = 15;
}
$ucContentLabel = strtoupper(preg_replace("/s$/", "", $contentsLabel));
$ucMethodLabel = strtoupper(preg_replace("/s$/", "", $methodsLabel));
$ucPersonLabel = "PERSON";
$width = 1200;
$height = 900;

echo "<script type='text/javascript'>
(function() {
    d3.selection.prototype.moveToFront = function() {
        return this.each(function(){
            this.parentNode.appendChild(this);
        });
    };
    var maxScholarsWithNamesShown = 120;
    var a = $width,
        c = $height,
        h = c,
        U = 150,
        K = ($numRows < 60) ? 16 : ($numRows < 75) ? 14 : ($numRows < 90) ? 12 : ($numRows < 100) ? 10 : ($numRows < maxScholarsWithNamesShown) ? 8 : ($numRows < 240) ? 4 : 2,
        S = 20,
        s = 8,
        R = 110,
        J = 30,
        o = 15,
        t = 10,
        w = 1000,
        F = 'elastic',
        peripheralCircleColor = '#ebebf8',
        accentColor = '#48489d',
        boxColor = '#5764ae',
        mainCircleColor = '#8dc63f',
        contentColor = '#b0d87a',
        methodColor = '#d4d4eb';
    var headerFontSize = 24;
    var T, q, x, j, H, A, P;
    var L = {},
        k = {};
    var i, y;
    var maxZCount = 0;
    var maxCircleSize = 40;
    var r = d3.layout.tree().size([360, h / 2 - R]).separation(function(Y, X) {
        return (Y.parent == X.parent ? 1 : 2) / Y.depth;
    });
    var W = d3.svg.diagonal.radial().projection(function(X) {
        return [X.y, X.x / 180 * Math.PI];
    });
    var v = d3.svg.line().x(function(X) {
        return X[0];
    }).y(function(X) {
        return X[1];
    }).interpolate('bundle').tension(0.5);
    var d = d3.select('#graph').append('svg').attr('width', a).attr('height', c).append('g').attr('transform', 'translate(' + a / 2 + ',' + c / 2 + ')');
    var I = d.append('rect').attr('class', 'bg').attr({
        x: a / -2,
        y: c / -2,
        width: a,
        height: c,
        fill: 'transparent'
    }).on('click', O);
    var B = d.append('g').attr('class', 'links'),
        f = d.append('g').attr('class', 'scholars'),
        E = d.append('g').attr('class', 'nodes');
    var Q = d3.select('#main-graph-info');
    var QList = d3.select('#list-graph-info');
    var X = {};
    var Y = $inputJSON;
    T = d3.map(Y);
    q = d3.merge(T.values());
    x = {};
    A = d3.map();
    q.forEach(function(aa) {
        aa.key = p(aa.name);
        aa.canonicalKey = aa.key;
        x[aa.key] = aa;
    });
    j = d3.map();
    T.get('scholars').forEach(function(aa) {
        aa.links = aa.links.filter(function(ab) {
            return typeof x[p(ab)] !== 'undefined' && ab.indexOf('r-') !== 0;
        });
        j.set(aa.key, aa.links.map(function(ab) {
            var ac = p(ab);
            if (typeof j.get(ac) === 'undefined') {
                j.set(ac, []);
            }
            j.get(ac).push(aa);
            return x[ac];
        }))
    });
    var Z = window.location.hash.substring(1);
    if (Z && x[Z]) {
        G(x[Z]);
    } else {
        O();
        M();
    }
    window.onhashchange = function() {
        var aa = window.location.hash.substring(1);
        if (aa && x[aa]) {
            G(x[aa], true);
        }
        if (window.location.hash) {
            hideHeaders();
        }
    }

    function O() {
        if (L.node === null) {
            return;
        }
        L = {
            node: null,
            map: {}
        };
        i = Math.floor(c / T.get('scholars').length);
        y = Math.floor(T.get('scholars').length * i / 2);
        T.get('scholars').forEach(function(af, ae) {
            af.x = U / -2;
            af.y = ae * i - y
        });
        var ad = 180 + J,
            Z = 360 - J,
            ac = (Z - ad) / (T.get('contents').length - 1);
        T.get('contents').forEach(function(af, ae) {
            af.x = Z - ae * ac;
            af.y = h / 2 - R;
            af.xOffset = -S;
            af.depth = 1;
        });
        ad = J;
        Z = 180 - J;
        ac = (Z - ad) / (T.get('methods').length - 1);
        T.get('methods').forEach(function(af, ae) {
            af.x = ae * ac + ad;
            af.y = h / 2 - R;
            af.xOffset = S;
            af.depth = 1;
        });
        H = [];
        var ab, Y, aa, X = h / 2 - R;
        T.get('scholars').forEach(function(ae) {
            ae.links.forEach(function(af) {
                ab = x[p(af)];
                if (!ab || ab.type === 'reference') {
                    return
                }
                Y = (ab.x - 90) * Math.PI / 180;
                aa = ae.key + '-to-' + ab.key;
                H.push({
                    source: ae,
                    target: ab,
                    key: aa,
                    canonicalKey: aa,
                    x1: ae.x + (ab.type === 'content' ? 0 : U),
                    y1: ae.y + K / 2,
                    x2: Math.cos(Y) * X + ab.xOffset,
                    y2: Math.sin(Y) * X
                });
            });
        });
        P = [];
        A.forEach(function(af, ag) {
            var ae = (ag[0].x - 90) * Math.PI / 180;
            a2 = (ag[1].x - 90) * Math.PI / 180, bulge = 20;
            P.push({
                x1: Math.cos(ae) * X + ag[0].xOffset,
                y1: Math.sin(ae) * X,
                xx: Math.cos((ae + a2) / 2) * (X + bulge) + ag[0].xOffset,
                yy: Math.sin((ae + a2) / 2) * (X + bulge),
                x2: Math.cos(a2) * X + ag[1].xOffset,
                y2: Math.sin(a2) * X
            });
        });
        window.location.hash = '';
        M();
    }

    function G(Y, X) {
        if (L.node === Y && X !== true) {
            L.node.children.forEach(function(aa) {
                aa.children = aa._group;
            });
            e();
            return;
        }
        if (Y.isGroup) {
            L.node.children.forEach(function(aa) {
                aa.children = aa._group;
            });
            Y.parent.children = Y.parent._children;
            e();
            return;
        }
        Y = x[Y.canonicalKey];
        q.forEach(function(aa) {
            aa.parent = null;
            aa.children = [];
            aa._children = [];
            aa._group = [];
            aa.canonicalKey = aa.key;
            aa.xOffset = 0;
        });
        L.node = Y;
        L.node.children = j.get(Y.canonicalKey);
        L.map = {};
        var Z = 0;
        maxCircleSize = getMaxCircleSize(L.node.children.length);
        L.node.children.forEach(function(ac) {
            L.map[ac.key] = true;
            ac._children = j.get(ac.key).filter(function(ad) {
                return ad.canonicalKey !== Y.canonicalKey;
            });
            ac._children = JSON.parse(JSON.stringify(ac._children));
            ac._children.forEach(function(ad) {
                ad.canonicalKey = ad.key;
                ad.key = ac.key + '-' + ad.key;
                L.map[ad.key] = true
            });
            var aa = ac.key + '-group',
                ab = ac._children.length;
                
            let objectType = (ac.type === 'scholar') ? 'Domains' : '$peopleLabel';
            if (ab === 1) {
                objectType = (ac.type === 'scholar') ? 'Domain' : 'Person';
            }
            ac._group = [{
                isGroup: true,
                key: aa + '-group-key',
                canonicalKey: aa,
                name: ab,
                groupType: objectType,
                count: ab,
                xOffset: 0
            }];
            if (ab > maxZCount) {
                maxZCount = ab;
            }
            L.map[aa] = true;
            Z += ab;
        });
        L.node.children.forEach(function(aa) {
            aa.children = Z > 50 ? aa._group : aa._children;
        });
        window.location.hash = L.node.key;
        e();
    }

    function n() {
        k = {
            node: null,
            map: {}
        };
        z();
    }
    
    function getMaxCircleSize(numNodes) {
        if (numNodes < 20) {
            return 70;
        } else if (numNodes < 30) {
            return 50;
        } else if (numNodes < 40) {
            return 30;
        } else if (numNodes < 60) {
            return 25;
        } else {
            return 20;
        }
    }

    function g(X) {
        if (k.node === X) {
            return;
        }
        k.node = X;
        k.map = {};
        k.map[X.key] = true;
        if (X.key !== X.canonicalKey) {
            k.map[X.parent.canonicalKey] = true;
            k.map[X.parent.canonicalKey + '-to-' + X.canonicalKey] = true;
            k.map[X.canonicalKey + '-to-' + X.parent.canonicalKey] = true;
        } else {
            j.get(X.canonicalKey).forEach(function(Y) {
                k.map[Y.canonicalKey] = true;
                k.map[X.canonicalKey + '-' + Y.canonicalKey] = true;
            });
            H.forEach(function(Y) {
                if (k.map[Y.source.canonicalKey] && k.map[Y.target.canonicalKey]) {
                    k.map[Y.canonicalKey] = true;
                }
            })
        }
        z()
    }

    function M() {
        V();
        B.selectAll('path').attr('d', function(X) {
            return v([
                [X.x1, X.y1],
                [X.x1, X.y1],
                [X.x1, X.y1]
            ]);
        }).transition().duration(w).ease(F).attr('d', function(X) {
            return v([
                [X.x1, transformForHeader(X.y1)],
                [X.target.xOffset * s, 0],
                [X.x2, X.y2]
            ]);
        });
        const cols = ['scholars', 'contents', 'methods'];
        const names = {};
        const arrays = {};
        for (let i=0; i < cols.length; i++) {
            const ary = T.get(cols[i]);
            const col = cols[i].replace(/s$/, '');
            arrays[cols[i]] = ary;
            names[col] = [];
            for (let j=0; j < ary.length; j++) {
                names[col].push(ary[j].name);
            }
        }

        D(arrays['scholars']);
        b(d3.merge([arrays['contents'], arrays['methods']]));
        C([]);
        m(P);
        Q.html('$contentsLabel are on the left, $peopleLabel are in the middle (names may be hidden), and $methodsLabel are on the right. Click to begin exploring.');
        QList.html(getListTitle()+makeTable(names));
        n();
        z()
    }
    
    function e() {
        var X = r.nodes(L.node);
        const cols = ['scholar', 'content', 'method'];
        const nodeNames = {};
        for (let i=0; i < cols.length; i++) {
            nodeNames[cols[i]] = [];
        }
        X.forEach(function(Z) {
            if (Z.name && !Number.isInteger(Z.name) && typeof nodeNames[Z.type] !== 'undefined') {
                nodeNames[Z.type].push(Z.name);
            }
            if (Z.depth === 1) {
                Z.y -= 20;
            }
        });
        H = r.links(X);
        H.forEach(function(Z) {
            Z.key = Z.target.canonicalKey + '-to-' + Z.source.canonicalKey;
            Z.canonicalKey = Z.key;
        });
        V();
        B.selectAll('path').transition().duration(w).ease(F).attr('d', W);
        D([]);
        b(X);
        C([L.node]);
        m([]);
        var Y = '';
        if (L.node.description) {
            Y = L.node.description;
        }
        Q.html(Y);
        QList.html(getListTitle()+makeTable(nodeNames));
        n();
        z()
    }
    
    function getListTitle() {
        return '<h3>Relevant $conjugation</h3>';
    }
    
    function makeTable(names) {
        let hasData = false;
        let html = '<table style=\"margin: 0 auto; text-align: center;\"><tbody><tr>'
        for (let col in names) {
            if (names[col].length > 0) {
                let header = '$peopleLabel';
                if (col === 'content') {
                    header = '$contentsLabel';
                } else if (col === 'method') {
                    header = '$methodsLabel';
                }
                hasData = true;
                html += '<td style=\"vertical-align: top; max-width: 300px; padding-left: 10px; padding-right: 10px;\"><h4>'+header+'</h4><p style=\"margin: 8px auto;\">'+names[col].join('</p><p style=\"margin: 8px auto;\">')+'</p></td>';
            }
        }
        if (!hasData) {
            return '<p>None.</p>';
        }
        html += '</tr></tbody></table>';
        return html;
    }

    function b(X) {
        var X = E.selectAll('.node').data(X, u);
        var Y = X.enter().append('g').attr('transform', function(aa) {
            var Z = aa.parent ? aa.parent : {
                xOffset: 0,
                x: 0,
                y: 0
            };
            return 'translate(' + Z.xOffset + ',0)rotate(' + (Z.x - 90) + ')translate(' + Z.y + ')';
        }).attr('class', 'node').on('mouseover', g).on('mouseout', n).on('click', G);
        Y.append('circle').attr('r', 0);
        Y.append('text').attr('stroke', '#ddd').attr('stroke-width', 2).attr('class', 'label-stroke');
        Y.append('text').attr('font-size', 0).attr('class', 'label');
        X.transition().duration(w).ease(F).attr('transform', function(Z) {
            if (Z === L.node) {
                return null;
            }
            var aa = Z.isGroup ? Z.y + getScaledSize(Z.count) : Z.y;
            return 'translate(' + Z.xOffset + ',0)rotate(' + (Z.x - 90) + ')translate(' + aa + ')';
        });
        X.selectAll('circle').transition().duration(w).ease(F).attr('r', function(Z) {
            if (Z == L.node) {
                return 100;
            } else {
                if (Z.isGroup) {
                    return getScaledSize(Z.count);
                } else {
                    return 4.5;
                }
            }
        });
        X.selectAll('text').transition().duration(w).ease(F).attr('dy', function(Z) {
            if (Z == L.node) {
                return '-0.5em';
            }
            if (Z.isGroup) {
                return '0';
            }
            if (!window.location.hash) {
                return '0em';
            }
            return '-0.2em';
        }).attr('font-size', function(Z) {
            return getFontSize(Z);
        }).text(function(Z) {
            return Z.name;
        }).attr('text-anchor', function(Z) {
            if (Z === L.node || Z.isGroup) {
                return 'middle'
            }
            return Z.x < 180 ? 'start' : 'end'
        }).attr('transform', function(Z) {
            if (Z === L.node) {
                return null;
            } else {
                if (Z.isGroup) {
                    return Z.x > 180 ? 'rotate(180)' : null;
                }
            }
            return Z.x < 180 ? 'translate(' + t + ')' : 'rotate(180)translate(-' + t + ')';
            return Z.x < 180 ? 'translate(' + t + ')' : 'rotate(180)translate(-' + t + ')';
        })
        X.on('mouseover', function(Z) {
            g(Z);
            d3.select(this).select('text.label-stroke')
                .attr('font-size', function(Z) {
                    return increaseFontSizeIfSmall(Z);
                });
            d3.select(this).select('text.label')
                .attr('font-size', function(Z) {
                    return increaseFontSizeIfSmall(Z);
                }).attr('fill', '#000');
        });
        X.on('mouseout', function(Z) {
            n(Z);
            d3.select(this).select('text.label-stroke')
                .attr('font-size', function(Z) {
                    return getFontSize(Z);
                });
            d3.select(this).select('text.label')
                .attr('font-size', function(Z) {
                    return getFontSize(Z);
                });
        });
        X.selectAll('text.label').attr('class', function(Z) {
            let className = 'label';
            if ((Z.type === 'method' || Z.type === 'content') && window.location.hash && Z.name.length > 25) {
                className += ' multiline';
            }
            if (Z.isGroup) {
                className += ' twoLine';
            }
            return className;
        });
        X.selectAll('text.label-stroke').attr('class', function(Z) {
            if ((Z.type === 'method' || Z.type === 'content') && window.location.hash && Z.name.length > 25) {
                return 'label-stroke multiline';
            } else {
                return 'label-stroke';
            }
        });
        setTimeout(() => {
            var ml = X.selectAll('text.multiline');
            X.selectAll('.twoLine tspan').remove();
            var twoLine = X.selectAll('.twoLine');
            twoLine.append('tspan')
                .attr('dy', 7)
                .attr('x', 0)
                .text(function(Z) {
                    return Z.groupType;
                })
                .attr('font-size', 9);
            
            ml.text(function(Z) {
                const lines = FlightConnector.wrap(Z.name).split(/[\\n\\r]+/);
                return lines[0].trim();
             }).append('tspan')
                .attr('x', 0)
                .attr('dy', function(Z) {
                    return getFontSize(Z);
                })
                .text(function(Z) {
                    const lines = FlightConnector.wrap(Z.name).split(/[\\n\\r]+/);
                    let elipses = '';
                    if ((L.node.type === 'method' || L.node.type === 'content') && (Z.type === 'method' || Z.type === 'content') && L.node != Z) {
                        elipses = '...'
                    }
                    return lines[1].trim()+elipses;
                });
            ml.append('tspan')
                .attr('x', 0)
                .attr('dy', function(Z) {
                    return getFontSize(Z);
                })
                .text(function(Z) {
                    if ((L.node.type === 'method' || L.node.type === 'content') && (Z.type === 'method' || Z.type === 'content') && L.node != Z) {
                        return '';
                    }
                    const lines = FlightConnector.wrap(Z.name).split(/[\\n\\r]+/);
                    const line = lines[2];
                    if (line) {
                        return line.trim();
                    } else {
                        return '';
                    }
                });
            ml.append('tspan')
                .attr('x', 0)
                .attr('dy', function(Z) {
                    return getFontSize(Z);
                }).text(function(Z) {
                    if ((L.node.type === 'method' || L.node.type === 'content') && (Z.type === 'method' || Z.type === 'content') && L.node != Z) {
                        return '';
                    }
                    const lines = FlightConnector.wrap(Z.name).split(/[\\n\\r]+/);
                    const line = lines[3] ? lines[3].trim() : '';
                    const elipses = (lines.length > 4) ? '...' : '';
                    return line+elipses; 
                });
        }, 30);
        
        X.selectAll('text.label-stroke').attr('display', 'block');
        X.exit().remove();
        
        if (!window.location.hash) {
            addHeaders(Y);
        } else {
            hideHeaders();
        }
    }
    
    function getScaledSize(ZCount) {
        return 7 + (ZCount * maxCircleSize / maxZCount);
    }
    
    function increaseFontSizeIfSmall(Z) {
        const fontSize = getFontSize(Z);
        if (fontSize <= 14) {
            return 14;
        }
        return fontSize;
    }
    
    function getFontSize(Z) {
        if (Z.depth === 0) {
            return 20;
        } else if ((Z.type === 'method') || (Z.type === 'content')) {
            if (window.location.hash && (L.node.type === 'method'  || L.node.type === 'theme')) {
                return 12;
            } else if (window.location.hash) {
                return 14;
            } else {
                return $defaultFontSize;
            }
        } else if (Z.type === 'scholar' && L.node.type === 'method') {
            if (L.node.count > 80) {
                return 12;
            } else if (L.node.count > 60) {
                return 14;
            } else {
                return 15;
            }
        } else {
            return 15;
        }
    }

    function V() {
        var X = B.selectAll('path').data(H, u);
        X.enter().append('path').attr('d', function(Z) {
            var Y = Z.source ? {
                x: Z.source.x,
                y: Z.source.y
            } : {
                x: 0,
                y: 0
            };
            return W({
                source: Y,
                target: Y
            });
        }).attr('class', 'link');
        X.exit().remove();
    }

    function C(Z) {
        var ac = d.selectAll('.detail').data(Z, u);
        var Y = ac.enter().append('g').attr('class', 'detail');
        var ab = Z[0];
        if (ab && ab.type) {
            let text = '$ucPersonLabel';
            if (ab.type === 'content') {
                text = '$ucContentLabel';
            } else if (ab.type === 'method') {
                text = '$ucMethodLabel';
            }
            Y.append('text').attr('fill', '#888').attr('text-anchor', 'middle').attr('y', (o + t) * -2).text(text).attr('font-weight', 'bold').attr('font-size', 15);
        }
        ac.exit().remove();
    }
    
    function hideHeaders() {
        d.selectAll('#contentHeader').attr('display', 'none');
        d.selectAll('#methodHeader').attr('display', 'none');
        d.selectAll('#peopleHeader').attr('display', 'none');
        d.selectAll('#peopleSubHeader').attr('display', 'none');
    }
    
    function addHeaders(Z) {
        var X;

        X = d.selectAll('#a').data(Z);
        X.attr('display', 'block');
        X.enter().append('text').attr('id', 'contentHeader').attr('text-anchor', 'start').attr('x', a / -2 + t).attr('y', 0 - (c / 2 - 2 * t)).text('$contentsLabel').attr('class', 'header').attr('font-size', headerFontSize).on('click', O);
        X.exit().remove();

        X = d.selectAll('#a').data(Z);
        X.attr('display', 'block');
        X.enter().append('text').attr('id', 'methodHeader').attr('text-anchor', 'end').attr('x', 0 - (a / -2 + t)).attr('y', 0 - (c / 2 - 2 * t)).text('$methodsLabel').attr('class', 'header').attr('font-size', headerFontSize).on('click', O);
        X.exit().remove();

        const numScholars = T.get('scholars').length;        
        X = d.selectAll('#a').data(Z);
        X.attr('display', 'block');
        X.enter().append('text').attr('id', 'peopleHeader').attr('text-anchor', 'middle').attr('x', 0).attr('y', 0 - (c / 2 - 2 * t)).text(numScholars+' $peopleLabel').attr('class', 'header').attr('font-size', headerFontSize).on('click', O);
        if (numScholars >= maxScholarsWithNamesShown) {
            X.enter().append('text').attr('id', 'peopleSubHeader').attr('text-anchor', 'middle').attr('x', 0).attr('y', 0 - (c / 2 - 2 * t) + headerFontSize - 8).attr('class', 'header').text('(Scroll over names to view)').attr('class', 'header').attr('font-size', 12).on('click', O);
        }
        X.exit().remove();

    }
    
    function transformForHeader(y) {
        const floorOffset = 24;
        return  y * (c - headerFontSize - floorOffset) / c + headerFontSize;
    }

    function D(Y) {
        const offset = 1;
        const fontSize = (K >= 12) ? 12 : (K >= 10) ? K - 1 : 9;
        var Y = f.selectAll('.userid').data(Y, u);
        var X = Y.enter().append('g').attr('class', 'userid').on('mouseover', g).on('mouseout', n).on('click', G);
        X.append('rect').attr('x', U / -2).attr('y', K / -2).attr('width', U).attr('height', K).transition().duration(w).ease(F).attr('x', function(Z) {
            return Z.x;
        }).attr('y', function(Z) {
            return transformForHeader(Z.y);
        });
        X.append('text').attr('x', function(Z) {
            return U / -2 + t;
        }).attr('fill', '#fff').text(function(Z) {
            return ($numRows < maxScholarsWithNamesShown) ? Z.name : '';
        }).attr('font-size', fontSize).transition().duration(w).ease(F).attr('x', function(Z) {
            return Z.x + t;
        }).attr('y', function(Z) {
            return transformForHeader(Z.y) + fontSize - offset;
        });
        X.on('mouseover', function(Z) {
            var sel = d3.select(this);
            sel.moveToFront();
            sel.select('rect')
                .attr('fill', '#000')
                .attr('height', fontSize + offset);
            sel.select('text')
                .text(function(Z) {
                    return Z.name;
                });
        });
        X.on('mouseout', function(Z) {
            var sel = d3.select(this);
            sel.select('rect')
                .attr('fill', boxColor)
                .attr('height', K);
            sel.select('text')
                .text(function(Z) {
                    return ($numRows < maxScholarsWithNamesShown) ? Z.name : '';
                });
        });

        Y.exit().selectAll('rect').transition().duration(w).ease(F).attr('x', function(Z) {
            return U / -2;
        }).attr('y', function(Z) {
            return K / -2;
        });
        Y.exit().selectAll('text').transition().duration(w).ease(F).attr('x', function(Z) {
            return U / -2 + t;
        }).attr('y', function(Z) {
            return K / -2 + o;
        });
        Y.exit().transition().duration(w).remove();
    }

    function m(Y) {
        var X = f.selectAll('path').data(Y);
        X.enter().append('path').attr('d', function(Z) {
            return v([
                [Z.x1, Z.y1],
                [Z.x1, Z.y1],
                [Z.x1, Z.y1]
            ]);
        }).attr('stroke', '#000').attr('stroke-width', 1.5).transition().duration(w).ease(F).attr('d', function(Z) {
            return v([
                [Z.x1, Z.y1],
                [Z.xx, Z.yy],
                [Z.x2, Z.y2]
            ]);
        });
        X.exit().remove()
    }

    function z() {
        f.selectAll('rect').attr('fill', function(X) {
            return boxColor;
        });
        B.selectAll('path').attr('stroke', function(X) {
            return l(X, '#aaa', accentColor, '#aaa');
        }).attr('stroke-width', function(X) {
            const numScholarsShown = ($('.userid').length === 0) ? $('.node').length : $('.userid').length;
            if (numScholarsShown > 30) { 
                return l(X, '1px', '1px', '1px');
            } else {
                return l(X, '1.5px', '2.5px', '1px');
            }
        }).attr('opacity', function(X) {
            return l(X, 0.4, 0.75, 0.3);
        }).sort(function(Y, X) {
            if (!k.node) {
                return 0;
            }
            var aa = k.map[Y.canonicalKey] ? 1 : 0,
                Z = k.map[X.canonicalKey] ? 1 : 0;
            return aa - Z;
        });
        E.selectAll('circle').attr('fill', function(X) {
            if (X === L.node) {
                return mainCircleColor;
            } else if (X.type === 'content') {
                return l(X, contentColor, accentColor, '#000');
            } else if (X.type === 'method') {
                return l(X, methodColor, accentColor, '#000');
            } else if (X.type === 'scholar') {
                return l(X, boxColor, accentColor, '#999')
            }
            return l(X, peripheralCircleColor, accentColor, '#999')
        }).attr('stroke', function(X) {
            if (X === L.node) {
                return l(X, null, accentColor, null);
            } else if (X.type === 'content') {
                return '#000';
            } else if (X.type === 'method') {
                return '#000';
            } else if (X.type === 'scholar') {
                return '#000';
            }
            return null;
        }).attr('stroke-width', function(X) {
            if (X === L.node) {
                return l(X, null, 2.5, null);
            } else if (X.type === 'content' || X.type === 'method') {
                return 1.5;
            }
            return null;
        });
        E.selectAll('text.label').attr('fill', function(X) {
            return (X === L.node || X.isGroup) ? '#444' : l(X, '#000', accentColor, '#999');
        })
    }
    
    function p(X) {
        return FlightConnector.makeHash(X);
    }

    function u(X) {
        return X.key;
    }

    function l(X, aa, Z, Y) {
        if (k.node === null) {
            return aa;
        }
        return k.map[X.key] ? Z : aa;
    }
})();
</script>";

echo "</body></html>";