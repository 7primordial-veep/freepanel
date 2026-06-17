var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl)
})

function copyToClipboard(textToCopy) {
  var tempInput = $('<input>');
  $('body').append(tempInput);
  tempInput.val(textToCopy).select();
  document.execCommand('copy');
  tempInput.remove();
}

function toggleElement(id) {
  jQuery('#'+id).toggle();
}

function generatePassword(passwordLength) {
  var numberChars = "0123456789";
  var upperChars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  var lowerChars = "abcdefghijklmnopqrstuvwxyz";
  var allChars = numberChars + upperChars + lowerChars;
  var randPasswordArray = Array(passwordLength);
  randPasswordArray[0] = numberChars;
  randPasswordArray[1] = upperChars;
  randPasswordArray[2] = lowerChars;
  randPasswordArray = randPasswordArray.fill(allChars, 3);
  return shuffleArray(randPasswordArray.map(function(x) { return x[Math.floor(Math.random() * x.length)] })).join('');
}

function shuffleArray(array) {
  for (var i = array.length - 1; i > 0; i--) {
    var j = Math.floor(Math.random() * (i + 1));
    var temp = array[i];
    array[i] = array[j];
    array[j] = temp;
  }
  return array;
}

function convertDateToUnixTimestamp(date) {
  return Date.parse(date)/1000;
}

class UrlBuilder {
  constructor() {
    this.protocol = window.location.protocol;
    this.host = window.location.host;
    this.pathName = window.location.pathname;
    this.queryString = window.location.search;
    this.initParameters();
  }
  initParameters() {
    this.parameters = (this.queryString || document.location.search).replace(/(^\?)/,'').split("&").map(function(n){return n = n.split("="),this[n[0]] = n[1],this}.bind({}))[0];
  }
  setParameter(key, value) {
    this.parameters[key] = value;
  }
  getParameter(key) {
    if (this.parameters.hasOwnProperty(key)) {
      return this.parameters[key];
    }
  }
  removeParameter(key) {
    if (this.parameters.hasOwnProperty(key)) {
      delete this.parameters[key];
    }
  }
  getParameters() {
    return this.parameters;
  }
  getUrl() {
    this.url = this.protocol + '//' + this.host + this.pathName;
    var i = 0;
    for(var key in this.parameters) {
      if (this.parameters.hasOwnProperty(key) && key) {
        if (i == 0) {
          this.url += '?';
        } else {
          this.url += '&';
        }
        this.url += key + '=' + this.parameters[key];
        i += 1;
      }
    }
    return this.url;
  }
  setBrowserUrl(url) {
    window.history.pushState({path:url},'',url);
  }
}

jQuery(document).ready(function() {
  jQuery('form').submit(function() {
    jQuery(this).find('button[type="submit"]').prop('disabled',true);
  });
});