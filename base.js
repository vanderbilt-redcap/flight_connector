function wrap(s) {
    return s.replace(/(\S)\/(\S)/g, '$1 / $2')
            .replace(/(?![^\n]{1,25}$)([^\n]{1,25})\s/g, '$1\r\n');
}

