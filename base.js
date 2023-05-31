function wrap(s) {
    return s.replace(/(\S)\/(\S)/g, '$1 / $2')
            .replace(/(?![^\n]{1,30}$)([^\n]{1,30})\s/g, '$1\r\n');
}

