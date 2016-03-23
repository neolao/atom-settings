module.exports =
    ###*
     * Configuration settings.
    ###
    config:
        phpCommand:
            title       : 'PHP command'
            description : 'The path to your PHP binary (e.g. /usr/bin/php, php, ...).'
            type        : 'string'
            default     : 'php'
            order       : 1

    ###*
     * The name of the package.
    ###
    packageName: 'php-integrator-base'

    ###*
     * The configuration object.
    ###
    configuration: null

    ###*
     * The proxy object.
    ###
    proxy: null

    ###*
     * The parser object.
    ###
    parser: null

    ###*
     * Keeps track of files that are being indexed.
    ###
    indexMap: {}

    ###*
     * The exposed service.
    ###
    service: null

    ###*
     * The status bar manager.
    ###
    statusBarManager: null

    ###*
     * Whether project indexing is currently happening.
    ###
    isProjectIndexBusy: false

    ###*
     * A list of disposables to dispose when the package deactivates.
    ###
    disposables: null

    ###*
     * Tests the user's configuration.
     *
     * @return {boolean}
    ###
    testConfig: () ->
        ConfigTester = require './ConfigTester'

        configTester = new ConfigTester(@configuration)

        result = configTester.test()

        if not result
            errorMessage =
                "PHP is not correctly set up and as a result PHP integrator will not work. Please visit the settings
                 screen to correct this error. If you are not specifying an absolute path for PHP or Composer, make
                 sure they are in your PATH."

            atom.notifications.addError('Incorrect setup!', {'detail': errorMessage})

            return false

        return true

    ###*
     * Registers any commands that are available to the user.
    ###
    registerCommands: () ->
        atom.commands.add 'atom-workspace', "php-integrator-base:index-project": =>
            return @attemptProjectIndex()

        atom.commands.add 'atom-workspace', "php-integrator-base:force-index-project": =>
            return @attemptForceProjectIndex()

        atom.commands.add 'atom-workspace', "php-integrator-base:configuration": =>
            return unless @testConfig()

            atom.notifications.addSuccess 'Success', {
                'detail' : 'Your PHP integrator configuration is working correctly!'
            }

    ###*
     * Registers listeners for config changes.
    ###
    registerConfigListeners: () ->
        @configuration.onDidChange 'phpCommand', () =>
            @attemptProjectIndex()

    ###*
     * Indexes a list of directories.
     *
     * @param {array}    directories
     * @param {Callback} progressStreamCallback
     *
     * @return {Promise}
    ###
    performDirectoriesIndex: (directories, progressStreamCallback) ->
        pathStrings = ''

        for i,project of directories
            pathStrings += project.path

        md5 = require 'md5'

        indexDatabaseName = md5(pathStrings)

        @proxy.setIndexDatabaseName(indexDatabaseName)

        # TODO: Support multiple root project directories. We can't send these one by one, they need to all be sent at
        # the same time in one reindex action or cross-dependencies might not be picked up correctly.
        return @service.reindex(directories[0].path, null, progressStreamCallback)

    ###*
     * Indexes the project aynschronously.
     *
     * @return {Promise}
    ###
    performProjectIndex: () ->
        timerName = @packageName + " - Project indexing"

        console.time(timerName);

        if @statusBarManager
            @statusBarManager.setLabel("Indexing...")
            @statusBarManager.setProgress(null)
            @statusBarManager.show()

        successHandler = () =>
            if @statusBarManager
                @statusBarManager.setLabel("Indexing completed!")
                @statusBarManager.hide()

            console.timeEnd(timerName);

        failureHandler = () =>
            if @statusBarManager
                @statusBarManager.showMessage("Indexing failed!", "highlight-error")
                @statusBarManager.hide()

        progressStreamCallback = (progress) =>
            progress = parseFloat(progress)

            if not isNaN(progress)
                if @statusBarManager
                    @statusBarManager.setProgress(progress)
                    @statusBarManager.setLabel("Indexing... (" + progress.toFixed(2) + " %)")

        directories = @fetchProjectDirectories()

        return @performDirectoriesIndex(directories, progressStreamCallback).then(successHandler, failureHandler)

    ###*
     * Performs a project index, but only if one is not currently already happening.
     *
     * @return {Promise|null}
    ###
    attemptProjectIndex: () ->
        return null if @isProjectIndexBusy

        @isProjectIndexBusy = true

        handler = () =>
            @isProjectIndexBusy = false

        successHandler = handler
        failureHandler = handler

        return @performProjectIndex().then(successHandler, failureHandler)

    ###*
     * Forcibly indexes the project in its entirety by removing the existing indexing database first.
     *
     * @return {Promise|null}
    ###
    forceProjectIndex: () ->
        fs = require 'fs'

        try
            fs.unlinkSync(@proxy.getIndexDatabasePath())

        catch error
            # If the file doesn't exist, just bail out.

        return @attemptProjectIndex()

    ###*
     * Forcibly indexes the project in its entirety by removing the existing indexing database first, but only if a
     * project indexing operation is not already busy.
     *
     * @return {Promise|null}
    ###
    attemptForceProjectIndex: () ->
        return null if @isProjectIndexBusy

        return @forceProjectIndex()

    ###*
     * Indexes a file aynschronously.
     *
     * @param {string}      fileName The file to index.
     * @param {string|null} source   The source code of the file to index.
     *
     * @return {Promise}
    ###
    performFileIndex: (fileName, source = null) ->
        successHandler = () =>
            return

        failureHandler = () =>
            return

        return @service.reindex(fileName, source).then(successHandler, failureHandler)

    ###*
     * Performs a file index, but only if the file is not currently already being indexed (otherwise silently returns).
     *
     * @param {string}      fileName The file to index.
     * @param {string|null} source   The source code of the file to index.
     *
     * @return {Promise|null}
    ###
    attemptFileIndex: (fileName, source = null) ->
        return null if @isProjectIndexBusy

        if fileName not of @indexMap
            @indexMap[fileName] = {
                isBeingIndexed  : true
                nextIndexSource : null
            }

        else if @indexMap[fileName].isBeingIndexed
            # This file is already being indexed, so keep track of the most recent changes so we can index any changes
            # after the current indexing process finishes.
            @indexMap[fileName].nextIndexSource = source
            return null

        @indexMap[fileName].isBeingIndexed = true

        handler = () =>
            @indexMap[fileName].isBeingIndexed = false

            if @indexMap[fileName].nextIndexSource?
                nextIndexSource = @indexMap[fileName].nextIndexSource

                @indexMap[fileName].nextIndexSource = null

                @attemptFileIndex(fileName, nextIndexSource)

        successHandler = handler
        failureHandler = handler

        return @performFileIndex(fileName, source).then(successHandler, failureHandler)

    ###*
     * Fetches a list of current project directories (root folders).
     *
     * @return {array}
    ###
    fetchProjectDirectories: () ->
        directories = atom.project.getDirectories()

        # In very rare situations, Atom gives us atom://config as project path. Not sure if this is a bug or intended
        # behavior.
        directories = directories.filter (directory) ->
            return directory.path.indexOf('atom://') != 0

        return directories

    ###*
     * Attaches items to the status bar.
     *
     * @param {mixed} statusBarService
    ###
    attachStatusBarItems: (statusBarService) ->
        if not @statusBarManager
            StatusBarManager = require "./Widgets/StatusBarManager"

            @statusBarManager = new StatusBarManager()
            @statusBarManager.initialize(statusBarService)
            @statusBarManager.setLabel("Indexing...")

    ###*
     * Detaches existing items from the status bar.
    ###
    detachStatusBarItems: () ->
        if @statusBarManager
            @statusBarManager.destroy()
            @statusBarManager = null

    ###*
     * Activates the package.
    ###
    activate: ->
        Parser                = require './Parser'
        Service               = require './Service'
        AtomConfig            = require './AtomConfig'
        CachingProxy          = require './CachingProxy'

        {Emitter}             = require 'event-kit';
        {CompositeDisposable} = require 'atom';

        @disposables = new CompositeDisposable()

        @configuration = new AtomConfig(@packageName)

        # See also atom-autocomplete-php pull request #197 - Disabled for now because it does not allow the user to
        # reactivate or try again.
        # return unless @testConfig()
        @testConfig()

        @proxy = new CachingProxy(@configuration)

        emitter = new Emitter()
        @parser = new Parser(@proxy)

        @service = new Service(@proxy, @parser, emitter)

        @registerCommands()
        @registerConfigListeners()

        # In rare cases, the package is loaded faster than the project gets a chance to load. At that point, no project
        # directory is returned. The onDidChangePaths listener below will also catch that case.
        if @fetchProjectDirectories().length > 0
            @attemptProjectIndex()

        @disposables.add atom.project.onDidChangePaths (projectPaths) =>
            # NOTE: This listener is also invoked at shutdown with an empty array as argument, this makes sure we don't
            # try to reindex then.
            if @fetchProjectDirectories().length > 0
                @attemptProjectIndex()

        @disposables.add atom.workspace.observeTextEditors (editor) =>
            # Wait a while for the editor to stabilize so we don't reindex multiple times after an editor opens just
            # because the contents are still loading.
            setTimeout ( =>
                return if not @disposables

                @disposables.add editor.onDidStopChanging () =>
                    @onEditorDidStopChanging(editor)
            ), 1500

    ###*
     * Invoked when an editor stops changing.
     *
     * @param {TextEditor} editor
    ###
    onEditorDidStopChanging: (editor) ->
        return unless /text.html.php$/.test(editor.getGrammar().scopeName)

        path = editor.getPath()

        return if not path

        isContainedInProject = false

        for projectDirectory in atom.project.getDirectories()
            if path.indexOf(projectDirectory.path) != -1
                isContainedInProject = true
                break

        # Do not try to index files outside the project.
        if isContainedInProject
            @attemptFileIndex(path, editor.getBuffer().getText())

    ###*
     * Deactivates the package.
    ###
    deactivate: ->
        if @disposables
            @disposables.dispose()
            @disposables = null

    ###*
     * Sets the status bar service, which is consumed by this package.
    ###
    setStatusBarService: (service) ->
        @attachStatusBarItems(service)

        # This method is usually invoked after the indexing has already started, hence we can't unconditionally hide it
        # here or it will never be made visible again. However, we also don't want it to be visible for new Atom windows
        # that don't contain a project.
        if atom.project.getDirectories().length == 0
            @statusBarManager.hide()

        {Disposable} = require 'atom'

        return new Disposable => @detachStatusBarItems()

    ###*
     * Retrieves the service exposed by this package.
    ###
    getService: ->
        return @service
