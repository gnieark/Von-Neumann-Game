
function createElem(type, attributes) {
    var elem = document.createElement(type);
    for (var i in attributes) {
        elem.setAttribute(i, attributes[i]);
    }
    return elem;
}

const sessionToken = decodeURIComponent(cookieValue('vn_session'));

document.addEventListener("DOMContentLoaded", () => {
      

});