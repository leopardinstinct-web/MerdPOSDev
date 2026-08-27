(function(){
  'use strict';
  if(window.MERDPOSBrandAssets)return;
  const version='20260827brand4';
  const root='assets/brand/';
  window.MERDPOSBrandAssets=Object.freeze({
    version,
    lockup:`${root}merdpos-logo-approved.png?v=${version}`,
    mark:`${root}merdpos-mark.png?v=${version}`,
    wordmark:`${root}merdpos-wordmark.png?v=${version}`,
    tagline:`${root}merdpos-tagline.png?v=${version}`
  });
})();
