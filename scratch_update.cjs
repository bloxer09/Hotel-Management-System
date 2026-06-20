const fs = require('fs');
const path = require('path');

function walk(dir) {
    let results = [];
    const list = fs.readdirSync(dir);
    list.forEach(function(file) {
        file = path.join(dir, file);
        const stat = fs.statSync(file);
        if (stat && stat.isDirectory()) {
            results = results.concat(walk(file));
        } else {
            if(file.endsWith('.jsx')) results.push(file);
        }
    });
    return results;
}

const files = walk('resources/js/Pages');
files.forEach(f => {
    let content = fs.readFileSync(f, 'utf8');
    let original = content;
    
    // Add table-fixed to tables
    content = content.replace(/<table className="([^"]*)"/g, (match, p1) => {
        if(!p1.includes('table-fixed')) {
            return `<table className="${p1} table-fixed"`;
        }
        return match;
    });

    // Add cursor-default to all <th> that aren't SortableHeader (though SortableHeader is its own component)
    // Wait, the user said "when scrolling to headers, change the crusor style to normal instead of I"
    // Let's just do it in CSS.

    if(original !== content) {
        fs.writeFileSync(f, content, 'utf8');
        console.log('Updated ' + f);
    }
});
