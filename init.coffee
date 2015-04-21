# Your init script
#
# Atom will evaluate this file each time a new window is opened. It is run
# after packages are loaded/activated and after the previous editor state
# has been restored.
#
# An example hack to make opened Markdown files always be soft wrapped:
#
# path = require 'path'
#
# atom.workspaceView.eachEditorView (editorView) ->
#   editor = editorView.getEditor()
#   if path.extname(editor.getPath()) is '.md'
#     editor.setSoftWrap(true)

atom.packages.activatePackage('toolbar')
  .then (pkg) =>
    @toolbar = pkg.mainModule

    # Icons: http://fortawesome.github.io/Font-Awesome/icons/
    #@toolbar.appendButton 'octoface', 'application:about', 'About Atom'
    #@toolbar.appendSpacer()
    @toolbar.appendButton 'gear-a', 'application:show-settings', 'Show Settings', 'ion'
    @toolbar.appendSpacer()
    @toolbar.appendButton 'folder', 'project-manager:toggle', 'Toggle Project Manager'
    @toolbar.appendSpacer()
    @toolbar.appendButton 'git', 'git-control:toggle', 'Toggle Git Control'
