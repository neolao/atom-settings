$ = require 'jquery'
shell = require 'shell'

AbstractProvider = require './AbstractProvider'

module.exports =

##*
# Provides code navigation for classes (i.e. being able to click class, interface and trait names to navigate to them).
##
class ClassProvider extends AbstractProvider
    ###*
     * @inheritdoc
    ###
    eventSelectors: '.entity.inherited-class, .support.namespace, .support.class, .comment-clickable .region'

    ###*
     * A list of all markers that have been placed inside comments to allow code navigation there as well.
    ###
    markers: null

    ###*
     * @inheritdoc
    ###
    doActualInitialization: () ->
        super()

        @markers = {}

        atom.workspace.observeTextEditors (editor) =>
            @registerMarkers(editor)

        # Ensure annotations are updated.
        @service.onDidFinishIndexing (data) =>
            editor = @findTextEditorByPath(data.path)

            if editor?
                @rescanMarkers(editor)

    ###*
     * @inheritdoc
    ###
    deactivate: () ->
        super()

        @removeMarkers()

    ###*
     * Retrieves the text editor that is managing the file with the specified path.
     *
     * @param {string} path
     *
     * @return {TextEditor|null}
    ###
    findTextEditorByPath: (path) ->
        for textEditor in atom.workspace.getTextEditors()
            if textEditor.getPath() == path
                return textEditor

        return null

    ###*
     * @inheritdoc
    ###
    getClickedTextByEvent: (editor, event) ->
        selector = event.currentTarget

        return null unless selector

        # Class names inside comments require special treatment as their div doesn't actually contain any text, so we
        # use markers to fetch the text instead.
        if selector.className.indexOf('region') != -1
            longTitle = editor.getLongTitle()

            return if longTitle not of @markers

            bufferPosition = atom.views.getView(editor).component.screenPositionForMouseEvent(event)

            markerProperties =
                containsBufferPosition: bufferPosition

            markers = editor.findMarkers(markerProperties)

            for key,marker of markers
                for allMarker in @markers[longTitle]
                    if marker.id == allMarker.id
                        return marker.getProperties().term

        return super(editor, event)

    ###*
     * Convenience method that returns information for the specified term.
     *
     * @param {TextEditor} editor
     * @param {Point}      bufferPosition
     * @param {string}     term
    ###
    getInfoFor: (editor, bufferPosition, term) ->
        return null if not term

        scopeChain = editor.scopeDescriptorForBufferPosition(bufferPosition).getScopeChain()

        try
            className = term
            doResolve = true

            # Don't attempt to resolve class names in use statements.
            if scopeChain.indexOf('.support.other.namespace.use') != -1
                currentClassName = @service.determineCurrentClassName(editor, bufferPosition)

                # Scope descriptors for trait use statements and actual "import" use statements are the same, so we
                # have no choice but to use class information for this.
                if not currentClassName?
                    doResolve = false

            if doResolve
                className = @service.resolveTypeAt(editor, bufferPosition, className)

            classInfo = @service.getClassInfo(className)

        catch error
            return null

        return classInfo

    ###*
     * @inheritdoc
    ###
    isValid: (editor, bufferPosition, term) ->
        return if @getInfoFor(editor, bufferPosition, term)? then true else false

    ###*
     * @inheritdoc
    ###
    gotoFromWord: (editor, bufferPosition, term) ->
        info = @getInfoFor(editor, bufferPosition, term)

        if info?
            if info.filename?
                atom.workspace.open(info.filename, {
                    initialLine    : (info.startLine - 1),
                    searchAllPanes : true
                })

            else
                shell.openExternal(@config.get('php_documentation_base_urls').classes + info.name)

    ###*
     * @inheritdoc
    ###
    getHoverSelectorFromEvent: (event) ->
        return @service.getClassSelectorFromEvent(event)

    ###*
     * @inheritdoc
    ###
    getClickSelectorFromEvent: (event) ->
        # if event.currentTarget.className.indexOf('region') != -1
            # Class name inside a comment, we can't fetch the text of these elements (it will be empty), this is handled
            # by our override of registerEvents instead.
            # return null

        return @service.getClassSelectorFromEvent(event)

    ###*
     * Register any markers that you need.
     *
     * @param {TextEditor} editor The editor to search through.
    ###
    registerMarkers: (editor) ->
        text = editor.getText()
        rows = text.split('\n')

        for key,row of rows
            regex = /@param|@var|@return|@throws|@see/g

            if regex.test(row)
                @addMarkerToCommentLine(row.split(' '), parseInt(key), editor, true)

    ###*
     * Removes any annotations that were created for the specified editor.
     *
     * @param {TextEditor} editor
    ###
    removeMarkersFor: (editor) ->
        @removeMarkersByKey(editor.getLongTitle())

    ###*
     * Removes any annotations that were created with the specified key.
     *
     * @param {string} key
    ###
    removeMarkersByKey: (key) ->
        for i,marker of @markers[key]
            marker.destroy()

        @markers[key] = []

    ###*
     * Removes any annotations (across all editors).
    ###
    removeMarkers: () ->
        for key,markers of @markers
            @removeMarkersByKey(key)

        @markers = {}

    ###*
     * Rescans the editor, updating all annotations.
     *
     * @param {TextEditor} editor The editor to search through.
    ###
    rescanMarkers: (editor) ->
        @removeMarkersFor(editor)
        @registerMarkers(editor)

    ###*
     * Analyses the words array given for any classes and then creates a marker for them.
     *
     * @param {array} words           The array of words to check.
     * @param {int} rowIndex          The current row the words are on within the editor.
     * @param {TextEditor} editor     The editor the words are from.
     * @param {bool} shouldBreak      Flag to say whether the search should break after finding 1 class.
     * @param {int} currentIndex  = 0 The current column index the search is on.
     * @param {int} offset        = 0 Any offset that should be applied when creating the marker.
    ###
    addMarkerToCommentLine: (words, rowIndex, editor, shouldBreak, currentIndex = 0, offset = 0) ->
        for key,value of words
            regex = /^\\?([A-Za-z0-9_]+)\\?([A-Za-zA-Z_\\]*)?/g

            if regex.test(value) && @service.isBasicType(value) == false
                if value.includes('|')
                    @addMarkerToCommentLine value.split('|'), rowIndex, editor, false, currentIndex, parseInt(key)

                else
                    range = [
                        [rowIndex, currentIndex + parseInt(key) + offset],
                        [rowIndex, currentIndex + parseInt(key) + value.length + offset]
                    ]

                    # NOTE: New markers are added on startup as initialization is done, so making them persistent will cause the
                    # 'storage' file of the project (in Atom's config folder) to grow forever (in a way it's a memory leak).
                    marker = editor.markBufferRange(range, {
                        persistent : false
                    })

                    markerProperties =
                        term: value

                    marker.setProperties markerProperties

                    options =
                        type: 'highlight'
                        class: 'comment-clickable comment'

                    editor.decorateMarker marker, options

                    longTitle = editor.getLongTitle()

                    if longTitle not of @markers
                        @markers[longTitle] = []

                    @markers[longTitle].push(marker)

                if shouldBreak == true
                    break

            currentIndex += value.length;
