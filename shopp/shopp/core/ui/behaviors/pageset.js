/*
 * pageset.js - Pages settings behaviors
 * Copyright ?? 2011 by Ingenesis Limited. All rights reserved.
 * Licensed under the GPLv3 {@see license.txt}
 */
jQuery(document).ready(function(b){b.template("editor",b("#editor"));var a=false;b("#pages a.edit").click(function(j){j.preventDefault();var i=b(this),k=i.parents("tr").hide(),c=k.attr("id").substr(5),d=pages[c]?pages[c]:{},h=b.extend({id:"edit-"+c+"-page",name:c,classnames:k.attr("class")},d),g=b.tmpl("editor",h),f=g.find("a.cancel");i.cancel=function(l){if(l){l.preventDefault()}a=false;g.remove();k.fadeIn("fast")};f.click(i.cancel);if(a){a.cancel(false)}g.insertAfter(k);a=i})});