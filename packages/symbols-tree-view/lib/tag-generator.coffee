{BufferedProcess, Point} = require 'atom'
Q = require 'q'
path = require 'path'

module.exports =
  class TagGenerator
    constructor: (@path, @scopeName) ->

    parseTagLine: (line) ->
      sections = line.split('\t')
      if sections.length > 3
        tag = {
          position: new Point(parseInt(sections[2]) - 1)
          name: sections[0]
          type: sections[3]
          parent: null
        }
        if sections.length > 4 and sections[4].search('signature:') == -1
          tag.parent = sections[4]
        return tag
      else
        return null

    getLanguage: ->
      return 'Cson' if path.extname(@path) in ['.cson', '.gyp']

      switch @scopeName
        when 'source.c'        then 'C'
        when 'source.c++'      then 'C++'
        when 'source.clojure'  then 'Lisp'
        when 'source.coffee'   then 'CoffeeScript'
        when 'source.css'      then 'Css'
        when 'source.css.less' then 'Css'
        when 'source.css.scss' then 'Css'
        when 'source.gfm'      then 'Markdown'
        when 'source.go'       then 'Go'
        when 'source.java'     then 'Java'
        when 'source.js'       then 'JavaScript'
        when 'source.js.jsx'   then 'JavaScript'
        when 'source.jsx'      then 'JavaScript'
        when 'source.json'     then 'Json'
        when 'source.makefile' then 'Make'
        when 'source.objc'     then 'C'
        when 'source.objc++'   then 'C++'
        when 'source.python'   then 'Python'
        when 'source.ruby'     then 'Ruby'
        when 'source.sass'     then 'Sass'
        when 'source.yaml'     then 'Yaml'
        when 'text.html'       then 'Html'
        when 'text.html.php'   then 'Php'

    generate: ->
      deferred = Q.defer()
      tags = []
      command = path.resolve(__dirname, '..', 'vendor', "ctags-#{process.platform}")
      defaultCtagsFile = require.resolve('./.ctags')
      args = ["--options=#{defaultCtagsFile}", '--fields=KsS']

      if atom.config.get('symbols-view.useEditorGrammarAsCtagsLanguage')
        if language = @getLanguage()
          args.push("--language-force=#{language}")

      args.push('-nf', '-', @path)

      stdout = (lines) =>
        for line in lines.split('\n')
          if tag = @parseTagLine(line)
            tags.push(tag)
      exit = ->
        deferred.resolve(tags)

      new BufferedProcess({command, args, stdout, exit})

      deferred.promise
