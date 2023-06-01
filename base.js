function wrap(s) {
    return s.replace(/(\S)\/(\S)/g, '$1 / $2')
            .replace(/(?![^\n]{1,25}$)([^\n]{1,25})\s/g, '$1\r\n');
}

/**
 * Adapted from https://ramblings.mcpher.com/gassnippets2/converting-svg-to-png-with-javascript/
 * converts an svg string to base64 png using the domUrl and forces download
 * @param {string} svgText the svgtext
 * @param {number} [margin=0] the width of the border - the image size will be height+margin by width+margin
 * @param {string} [fill] optionally background canvas fill
 * @param {string} canvasFunction
 * @param {string} fontName name of font used
 * @param {string} woffFontBase64 the base-64 for a data url for the font
 * @return {Promise} a promise to the base64 png image
 */
function downloadSVG(svgText, margin,fill, canvasFunction, fontName, woffFontBase64) {
    // convert an svg text to png using the browser
    return new Promise(function (resolve, reject) {
        try {
            // can use the domUrl function from the browser
            let domUrl = window.URL || window.webkitURL || window;
            if (!domUrl) {
                throw new Error("(browser doesnt support this)")
            }

            // figure out the height and width from svg text
            let match = svgText.match(/height=\"(\d+)/m);
            let height = match && match[1] ? parseInt(match[1], 10) : 200;
            match = svgText.match(/width=\"(\d+)/m);
            let width = match && match[1] ? parseInt(match[1], 10) : 200;
            margin = margin || 0;

            // it needs a namespace
            if (!svgText.match(/xmlns=\"/mi)) {
                svgText = svgText.replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ');
            }

            // include font if applicable
            if (fontName && woffFontBase64) {
                let matches = svgText.match(/<svg [^>]+?>/);
                if (matches) {
                    const css = '@font-face { font-family: \''+fontName+'\'; src: url(data:font/woff2;base64,'+woffFontBase64+') format(\'woff2\'); }\n';
                    if (svgText.match(/<defs>/i)) {
                        svgText = svgText.replace(/<defs>/i, '<defs><style>\n'+css+'</style>')
                    } else {
                        svgText = svgText.replace(/<svg [^>]+?>/, matches[0]+'<defs><style>\n'+css+'</style></defs>')
                    }
                }
            }

            // create a canvas element to pass through
            let canvas = document.createElement("canvas");
            canvas.width = width + margin * 2;
            canvas.height = height + margin * 2;
            let ctx = canvas.getContext("2d");

            // make a blob from the svg
            let svg = new Blob([svgText], {
                type: "image/svg+xml;charset=utf-8"
            });

            // create a dom object for that image
            let url = domUrl.createObjectURL(svg);

            // create a new image to hold it the converted type
            let img = new Image;

            // when the image is loaded we can get it as base64 url
            img.onload = function () {
                // draw it to the canvas
                ctx.drawImage(this, margin, margin);

                // if it needs some styling, we need a new canvas
                if (fill) {
                    var styled = document.createElement("canvas");
                    styled.width = canvas.width;
                    styled.height = canvas.height;
                    var styledCtx = styled.getContext("2d");
                    styledCtx.save();
                    styledCtx.fillStyle = fill;
                    styledCtx.fillRect(0, 0, canvas.width, canvas.height);
                    styledCtx.strokeRect(0, 0, canvas.width, canvas.height);
                    styledCtx.restore();
                    styledCtx.drawImage(canvas, 0, 0);
                    canvas = styled;
                }
                // we don't need the original any more
                domUrl.revokeObjectURL(url);
                // now we can resolve the promise, passing the base64 url
                const downloadUrl = canvasFunction(canvas);
                forceDownloadUrl(downloadUrl, 'chart.png');

                resolve(downloadUrl);
            };

            // load the image
            img.src = url;

        } catch (err) {
            reject('failed to convert svg to png ' + err);
        }
    });
}

/**
 * Adapted from https://ramblings.mcpher.com/gassnippets2/converting-svg-to-png-with-javascript/
 * converts an svg string to base64 png using the domUrl and forces download
 * @param {string} svgText the svgtext
 * @param {number} [margin=0] the width of the border - the image size will be height+margin by width+margin
 * @param {string} [fill] optionally background canvas fill
 * @param {string} canvasFunction
 * @param {string} fontName name of font used
 * @param {string} woffFontLocation location of WOFF2 font file to import
 * @return {Promise} a promise to the base64 png image
 */
function svg2Image(svgText, margin,fill, canvasFunction, fontName, woffFontLocation) {
    try {
        if (fontName && woffFontLocation) {
            return new Promise(function(resolve, reject) {
                return fetch(woffFontLocation)
                    .then((resp) => {
                        return resp.blob();
                    })
                    .then((blob) => {
                        let f = new FileReader();
                        f.addEventListener('load', () => {
                            const base64 = f.result.replace(/^data:application\/octet-stream;base64,/, '');
                            resolve(downloadSVG(svgText, margin, fill, canvasFunction, fontName, base64));
                        });
                        f.readAsDataURL(blob);
                    })
                    .catch(err => {
                        reject('failed to read font ' + err);
                    });
            });
        } else {
            return downloadSVG(svgText, margin, fill, canvasFunction);
        }
    } catch (err) {
        reject('failed to set up reading a font' + err);
    }
}

function canvas2PNG(canvasOb) {
    return canvasOb.toDataURL("image/png");
}

function canvas2JPEG(canvasOb) {
    return canvasOb.toDataURL("image/jpeg");
}

function forceDownloadUrl(source, fileName){
    var el = document.createElement("a");
    el.setAttribute("href", source);
    el.setAttribute("download", fileName);
    document.body.appendChild(el);
    el.click();
    el.remove();
}

function makeHash(str) {
    return str.toLowerCase().replace(/[ .,()]/g, '-');
}

function filterSelect(ob) {
    $('.combobox').combobox();
}