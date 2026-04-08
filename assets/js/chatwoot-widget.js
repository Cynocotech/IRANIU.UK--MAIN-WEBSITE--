(function (d, t) {
  var BASE_URL = 'https://support.iraniu.uk';
  var g = d.createElement(t),
    s = d.getElementsByTagName(t)[0];
  g.src = BASE_URL + '/packs/js/sdk.js';
  g.defer = true;
  g.async = true;
  s.parentNode.insertBefore(g, s);
  g.onload = function () {
    window.chatwootSDK.run({
      websiteToken: '5ncXWzgu2LuBb8J8mXicPu8K',
      baseUrl: BASE_URL,
    });
  };
})(document, 'script');
